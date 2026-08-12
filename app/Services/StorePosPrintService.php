<?php

namespace App\Services;

use App\Models\StorePosPrint;
use App\Models\StoreWarranty;
use App\Support\WarrantyQr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StorePosPrintService
{
    public function enabled(): bool
    {
        // Same Ethernet POS / kitchen agent
        return (bool) config('kitchen_print.enabled', false);
    }

    public function enqueueBarcode(StoreWarranty $warranty): StorePosPrint
    {
        $serial = (string) $warranty->serial;
        $title = $warranty->product_name ?: ('ПК #'.($warranty->store_built_pc_id ?: $warranty->id));
        $ends = $warranty->ends_at?->format('d.m.Y') ?? '—';

        $lines = [
            'ГАРАНТИЯ / QR',
            $title,
            'S/N '.$serial,
            'До '.$ends,
            now()->format('d.m.Y H:i'),
        ];

        return StorePosPrint::query()->create([
            'club_id' => $warranty->club_id,
            'store_warranty_id' => $warranty->id,
            'kind' => StorePosPrint::KIND_QR,
            'serial' => mb_substr($serial, 0, 32),
            'payload_text' => implode("\n", $lines),
            'status' => StorePosPrint::STATUS_PENDING,
        ]);
    }

    /**
     * ESC/POS: centered text + QR (serial + warranty end) + cut.
     */
    public function buildQrEscPos(string $qrData, string $text): string
    {
        $esc = "\x1B";
        $gs = "\x1D";

        $out = $esc.'@';
        $out .= $esc.'t'."\x11"; // CP866
        $out .= $esc.'a'."\x01"; // center

        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $i => $row) {
            if ($i === 0) {
                $out .= $esc.'E'."\x01";
                $out .= $this->encodeLine($row)."\n";
                $out .= $esc.'E'."\x00";
            } else {
                $out .= $this->encodeLine($row)."\n";
            }
        }

        $out .= "\n";
        $out .= $this->appendNativeQr($qrData, 6);
        $out .= "\n\n\n";
        $out .= $gs.'V'."\x00";

        return $out;
    }

    /** @deprecated Use buildQrEscPos */
    public function buildBarcodeEscPos(string $serial, string $text): string
    {
        return $this->buildQrEscPos(
            WarrantyQr::fromSerialAndEnds($serial, null),
            $text
        );
    }

    /**
     * Epson-compatible QR: GS ( k model / size / ECC / store / print.
     */
    private function appendNativeQr(string $data, int $moduleSize = 6): string
    {
        $gs = "\x1D";
        $moduleSize = max(3, min(16, $moduleSize));
        $out = '';

        // Function 165: QR model 2
        $out .= $gs."(k\x04\x00\x31\x41\x32\x00";
        // Function 167: module size
        $out .= $gs.'(k'."\x03\x00\x31\x43".chr($moduleSize);
        // Function 169: error correction M
        $out .= $gs."(k\x03\x00\x31\x45\x31";
        // Function 180: store symbol data
        $store = "\x31\x50\x30".$data;
        $len = strlen($store);
        $out .= $gs.'(k'.chr($len & 0xFF).chr(($len >> 8) & 0xFF).$store;
        // Function 181: print
        $out .= $gs."(k\x03\x00\x31\x51\x30";

        return $out;
    }

    private function encodeLine(string $line): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP866//TRANSLIT//IGNORE', $line);
            if ($converted !== false) {
                return $converted;
            }
            $converted = @iconv('UTF-8', 'CP1251//TRANSLIT//IGNORE', $line);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $line;
    }

    /**
     * @return list<array{id:int,order_id:int,text:string,escpos_base64:string}>
     */
    public function claimPending(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return DB::transaction(function () use ($limit) {
            /** @var Collection<int, StorePosPrint> $jobs */
            $jobs = StorePosPrint::query()
                ->where('status', StorePosPrint::STATUS_PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $out = [];
            foreach ($jobs as $job) {
                $job->status = StorePosPrint::STATUS_CLAIMED;
                $job->claimed_at = now();
                $job->attempts = (int) $job->attempts + 1;
                $job->save();

                $warranty = $job->store_warranty_id
                    ? StoreWarranty::query()->find($job->store_warranty_id)
                    : null;
                $qrData = $warranty
                    ? WarrantyQr::payload($warranty)
                    : WarrantyQr::fromSerialAndEnds((string) $job->serial, null);

                $raw = $this->buildQrEscPos($qrData, (string) $job->payload_text);
                $out[] = [
                    'id' => StorePosPrint::toAgentId((int) $job->id),
                    'order_id' => 0,
                    'text' => (string) $job->payload_text,
                    'escpos_base64' => base64_encode($raw),
                ];
            }

            return $out;
        });
    }

    /**
     * @param  list<int>  $agentIds
     */
    public function markPrinted(array $agentIds): int
    {
        $ids = [];
        foreach ($agentIds as $aid) {
            $id = StorePosPrint::fromAgentId((int) $aid);
            if ($id) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return 0;
        }

        return StorePosPrint::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [StorePosPrint::STATUS_CLAIMED, StorePosPrint::STATUS_PENDING])
            ->update([
                'status' => StorePosPrint::STATUS_PRINTED,
                'printed_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<array{id:int|string, error?:string}>  $rows
     */
    public function markFailed(array $rows): int
    {
        $updated = 0;
        foreach ($rows as $row) {
            $id = StorePosPrint::fromAgentId((int) ($row['id'] ?? 0));
            if (! $id) {
                continue;
            }
            $error = mb_substr((string) ($row['error'] ?? 'print failed'), 0, 500);
            $n = StorePosPrint::query()
                ->where('id', $id)
                ->update([
                    'status' => StorePosPrint::STATUS_FAILED,
                    'last_error' => $error,
                    'updated_at' => now(),
                ]);
            $updated += $n;
        }

        return $updated;
    }

    public function releaseStaleClaims(int $minutes = 2): int
    {
        return StorePosPrint::query()
            ->where('status', StorePosPrint::STATUS_CLAIMED)
            ->where('claimed_at', '<', now()->subMinutes(max(1, $minutes)))
            ->update([
                'status' => StorePosPrint::STATUS_PENDING,
                'claimed_at' => null,
                'updated_at' => now(),
            ]);
    }
}
