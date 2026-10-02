<?php

namespace App\Services;

use App\Models\FiscalJob;
use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Очередь чеков для шлюза в клубе. Облако на регистратор не ходит.
 * Проведение и печать — разные задания; external_id фискализации не меняется.
 */
class FiscalGatewayService
{
    public const SEEN_CACHE_KEY = 'fiscal_gateway_seen_at';

    public const DEVICE_CACHE_KEY = 'fiscal_gateway_device';

    public function enqueueFiscalize(Transaction $transaction, array $payload): FiscalJob
    {
        return DB::transaction(function () use ($transaction, $payload) {
            $existing = FiscalJob::query()
                ->where('transaction_id', $transaction->id)
                ->where('kind', FiscalJob::KIND_FISCALIZE)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $externalId = (string) Str::uuid();
            $payload['external_id'] = $externalId;
            $payload['kind'] = FiscalJob::KIND_FISCALIZE;
            $payload['electronically'] = true;

            return FiscalJob::query()->create([
                'transaction_id' => $transaction->id,
                'kind' => FiscalJob::KIND_FISCALIZE,
                'external_id' => $externalId,
                'status' => FiscalJob::STATUS_PENDING,
                'payload' => $payload,
                'attempts' => 0,
            ]);
        });
    }

    public function enqueuePrintCopy(Transaction $transaction): FiscalJob
    {
        if ($transaction->fiscal_status !== 'success') {
            throw new InvalidArgumentException('Бумажная копия доступна после успешного проведения чека.');
        }

        $fiscalize = FiscalJob::query()
            ->where('transaction_id', $transaction->id)
            ->where('kind', FiscalJob::KIND_FISCALIZE)
            ->where('status', FiscalJob::STATUS_SUCCESS)
            ->first();

        if (! $fiscalize) {
            throw new InvalidArgumentException('Нет проведённого чека, ленту ставить нечего.');
        }

        return DB::transaction(function () use ($transaction, $fiscalize) {
            $open = FiscalJob::query()
                ->where('transaction_id', $transaction->id)
                ->where('kind', FiscalJob::KIND_PRINT_COPY)
                ->whereIn('status', [FiscalJob::STATUS_PENDING, FiscalJob::STATUS_CLAIMED])
                ->lockForUpdate()
                ->first();

            if ($open) {
                return $open;
            }

            $result = is_array($fiscalize->result) ? $fiscalize->result : [];
            $externalId = (string) Str::uuid();

            return FiscalJob::query()->create([
                'transaction_id' => $transaction->id,
                'kind' => FiscalJob::KIND_PRINT_COPY,
                'external_id' => $externalId,
                'status' => FiscalJob::STATUS_PENDING,
                'payload' => [
                    'external_id' => $externalId,
                    'kind' => FiscalJob::KIND_PRINT_COPY,
                    'source_external_id' => (string) $fiscalize->external_id,
                    'transaction_id' => (int) $transaction->id,
                    'fn' => (string) ($result['fn'] ?? ''),
                    'fd' => (string) ($result['fd'] ?? ''),
                    'fp' => (string) ($result['fp'] ?? ''),
                    'receipt_url' => (string) ($transaction->fiscal_receipt_url ?? ''),
                    'amount' => round(abs((float) $transaction->amount), 2),
                    'description' => (string) ($transaction->description ?? ''),
                ],
                'attempts' => 0,
            ]);
        });
    }

    /**
     * Повтор той же фискализации. Новый external_id не выдаётся.
     */
    public function requeueFiscalize(Transaction $transaction): FiscalJob
    {
        $job = FiscalJob::query()
            ->where('transaction_id', $transaction->id)
            ->where('kind', FiscalJob::KIND_FISCALIZE)
            ->first();

        if (! $job) {
            throw new InvalidArgumentException('Задания на проведение нет.');
        }

        if ($job->status === FiscalJob::STATUS_SUCCESS) {
            throw new InvalidArgumentException('Чек уже проведён. Повтор откроет второй фискальный документ.');
        }

        $job->forceFill([
            'status' => FiscalJob::STATUS_PENDING,
            'claimed_at' => null,
            'finished_at' => null,
            'last_error' => null,
        ])->save();

        $transaction->forceFill([
            'fiscal_status' => 'pending',
            'fiscal_error' => null,
            'fiscal_receipt_url' => null,
            'fiscal_at' => null,
        ])->save();

        return $job->fresh();
    }

    public function releaseStaleClaims(?int $minutes = null): int
    {
        $minutes = $minutes ?? (int) config('fiscal.stale_claim_minutes', 5);

        return FiscalJob::query()
            ->where('status', FiscalJob::STATUS_CLAIMED)
            ->where('claimed_at', '<', now()->subMinutes(max(1, $minutes)))
            ->update([
                'status' => FiscalJob::STATUS_PENDING,
                'claimed_at' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claimPending(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));

        return DB::transaction(function () use ($limit) {
            $jobs = FiscalJob::query()
                ->where('status', FiscalJob::STATUS_PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $out = [];
            foreach ($jobs as $job) {
                $job->status = FiscalJob::STATUS_CLAIMED;
                $job->claimed_at = now();
                $job->attempts = (int) $job->attempts + 1;
                $job->save();

                $payload = is_array($job->payload) ? $job->payload : [];
                $payload['external_id'] = (string) $job->external_id;
                $payload['kind'] = (string) $job->kind;
                $payload['electronically'] = $job->kind === FiscalJob::KIND_FISCALIZE;

                $out[] = [
                    'id' => (int) $job->id,
                    'external_id' => (string) $job->external_id,
                    'kind' => (string) $job->kind,
                    'transaction_id' => $job->transaction_id ? (int) $job->transaction_id : null,
                    'payload' => $payload,
                ];
            }

            return $out;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function applyResults(array $rows): int
    {
        $updated = 0;
        foreach ($rows as $row) {
            if ($this->applyOne($row)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyOne(array $row): bool
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            return false;
        }

        $job = FiscalJob::query()->find($id);
        if (! $job || $job->status === FiscalJob::STATUS_SUCCESS) {
            return false;
        }

        $outcome = (string) ($row['outcome'] ?? '');
        if (! in_array($outcome, ['success', 'error', 'uncertain'], true)) {
            return false;
        }

        $result = [
            'fn' => $this->short($row['fn'] ?? null, 32),
            'fd' => $this->short($row['fd'] ?? null, 32),
            'fp' => $this->short($row['fp'] ?? null, 32),
            'shift' => $this->short($row['shift'] ?? null, 16),
            'receipt_number' => $this->short($row['receipt_number'] ?? null, 16),
            'receipt_url' => $this->short($row['receipt_url'] ?? null, 500),
        ];
        $error = $this->short($row['error'] ?? null, 500);

        if ($outcome === 'success' && $job->kind === FiscalJob::KIND_FISCALIZE && ! $this->hasFiscalProof($result)) {
            $outcome = 'error';
            $error = $error ?: 'Регистратор не вернул фискальный признак';
        }

        $status = match ($outcome) {
            'success' => FiscalJob::STATUS_SUCCESS,
            'uncertain' => FiscalJob::STATUS_UNCERTAIN,
            default => FiscalJob::STATUS_ERROR,
        };

        $job->forceFill([
            'status' => $status,
            'result' => $result,
            'last_error' => $status === FiscalJob::STATUS_SUCCESS ? null : $error,
            'finished_at' => now(),
        ])->save();

        if ($job->kind === FiscalJob::KIND_FISCALIZE && $job->transaction_id) {
            $this->mirrorTransaction($job, $status, $result, $error);
        }

        return true;
    }

    /**
     * @param  array<string, ?string>  $result
     */
    private function mirrorTransaction(FiscalJob $job, string $status, array $result, ?string $error): void
    {
        $transaction = Transaction::query()->find($job->transaction_id);
        if (! $transaction || $transaction->fiscal_status === 'success') {
            return;
        }

        if ($status === FiscalJob::STATUS_SUCCESS) {
            $url = $result['receipt_url'] ?: null;
            $transaction->forceFill([
                'fiscal_status' => 'success',
                'fiscal_receipt_url' => $url,
                'receipt_id' => $result['fd'] ?: $url,
                'fiscal_error' => null,
                'fiscal_at' => now(),
            ])->save();

            return;
        }

        $transaction->forceFill([
            'fiscal_status' => $status === FiscalJob::STATUS_UNCERTAIN ? 'uncertain' : 'error',
            'fiscal_error' => $error ?: ($status === FiscalJob::STATUS_UNCERTAIN
                ? 'Неизвестно, пробит ли чек. Повтор только тем же заданием.'
                : 'Регистратор отклонил чек'),
            'fiscal_receipt_url' => null,
        ])->save();
    }

    /**
     * @param  array<string, ?string>  $result
     */
    private function hasFiscalProof(array $result): bool
    {
        if (filled($result['receipt_url'] ?? null)) {
            return true;
        }

        return filled($result['fn'] ?? null)
            && filled($result['fd'] ?? null)
            && filled($result['fp'] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $device
     */
    public function touch(?array $device = null): void
    {
        Cache::put(self::SEEN_CACHE_KEY, now()->toIso8601String(), now()->addDays(7));
        if (is_array($device) && $device !== []) {
            Cache::put(self::DEVICE_CACHE_KEY, $device, now()->addDays(7));
        }
    }

    public function lastSeenAt(): ?string
    {
        $value = Cache::get(self::SEEN_CACHE_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function gatewayFresh(): bool
    {
        $seen = $this->lastSeenAt();
        if ($seen === null) {
            return false;
        }

        $at = \Illuminate\Support\Carbon::parse($seen);
        $stale = (int) config('fiscal.gateway_stale_seconds', 90);

        return $at->greaterThan(now()->subSeconds(max(30, $stale)));
    }

    private function short(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
