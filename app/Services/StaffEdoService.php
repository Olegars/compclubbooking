<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Shift;
use App\Models\ShiftIntern;
use App\Models\ShiftSlotBooking;
use App\Models\StaffDisciplinaryIncident;
use App\Models\StaffEdoAgreement;
use App\Models\StaffEdoDocument;
use App\Models\StaffEdoOtp;
use App\Models\StaffPresencePing;
use App\Models\StaffQuarterReserve;
use App\Models\StaffSfrEvent;
use App\Support\WorkingDaysCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class StaffEdoService
{
    public function __construct(
        private readonly WorkingDaysCalculator $calendar,
        private readonly StaffEdoDocumentRenderer $docs,
    ) {
    }

    public function needsAgreement(Admin $admin): bool
    {
        if (! Schema::hasTable('staff_edo_agreements')) {
            return false;
        }
        if ($admin->isOwner() || $admin->isStoreRole() || $admin->needsEmployment() || $admin->isFired()) {
            return false;
        }
        if (! in_array($admin->role, ['admin', 'intern', 'supervisor'], true)) {
            return false;
        }

        $onboarded = $admin->hired_at !== null
            || $admin->employmentProfile?->status === 'approved';
        if (! $onboarded) {
            return false;
        }

        return ! StaffEdoAgreement::query()->where('admin_id', $admin->id)->exists();
    }

    public function isBlocked(Admin $admin): bool
    {
        return $this->blockingIncident($admin) !== null;
    }

    public function assertOperable(Admin $admin): void
    {
        if ($this->isBlocked($admin)) {
            throw new RuntimeException('Кабинет закрыт до решения по дисциплинарному инциденту.');
        }
        if ($this->needsAgreement($admin)) {
            throw new RuntimeException('Сначала подпишите соглашение о КЭДО.');
        }
    }

    public function blockingIncident(Admin $admin): ?StaffDisciplinaryIncident
    {
        if (! Schema::hasTable('staff_disciplinary_incidents')) {
            return null;
        }

        return StaffDisciplinaryIncident::query()
            ->where('admin_id', $admin->id)
            ->whereIn('status', StaffDisciplinaryIncident::BLOCKING)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyCabinet(): array
    {
        return [
            'needs_agreement' => false,
            'blocking' => false,
            'texts' => $this->texts(),
            'version' => (string) config('staff_edo.agreement_version'),
            'incident' => null,
            'documents' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cabinetPayload(Admin $admin): array
    {
        if (! Schema::hasTable('staff_edo_agreements')) {
            return $this->emptyCabinet();
        }

        $incident = $this->blockingIncident($admin);
        $documents = [];
        if ($incident) {
            $documents = $incident->documents()->orderBy('id')->get()->map(fn (StaffEdoDocument $doc) => [
                'id' => $doc->id,
                'title' => $doc->doc_title,
                'type' => $doc->doc_type,
            ])->all();
        }

        return [
            'needs_agreement' => $this->needsAgreement($admin),
            'blocking' => $incident !== null,
            'texts' => $this->texts(),
            'version' => (string) config('staff_edo.agreement_version'),
            'incident' => $incident ? $this->incidentCard($incident) : null,
            'documents' => $documents,
        ];
    }

    public function markDelivered(Admin $admin): void
    {
        $incident = $this->blockingIncident($admin);
        if (! $incident || $incident->status !== StaffDisciplinaryIncident::STATUS_DEMAND) {
            return;
        }
        if ($incident->demand_delivered_at) {
            return;
        }

        $incident->demand_delivered_at = now();
        $incident->deadline_at = $this->calendar->deadlineAfterDelivery(now());
        $incident->save();
    }

    /**
     * @return array{message: string}
     */
    public function sendOtp(Admin $admin, string $purpose, ?string $phone, ?string $telegramId): array
    {
        if (! in_array($purpose, [StaffEdoOtp::PURPOSE_AGREEMENT, StaffEdoOtp::PURPOSE_EXPLANATION], true)) {
            throw new RuntimeException('Неизвестное назначение кода.');
        }

        $recent = StaffEdoOtp::query()
            ->where('admin_id', $admin->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('created_at', '>=', now()->subSeconds(30))
            ->exists();
        if ($recent) {
            throw new RuntimeException('Подождите полминуты и запросите код снова.');
        }

        $phone = $this->normalizePhone($phone);
        if ($purpose === StaffEdoOtp::PURPOSE_AGREEMENT && $phone === null) {
            throw new RuntimeException('Укажите телефон для кода.');
        }
        if ($purpose === StaffEdoOtp::PURPOSE_EXPLANATION) {
            $agreement = $this->agreement($admin);
            $phone = $agreement->phone_number;
            $telegramId = $agreement->telegram_id;
        }

        $code = $this->makeCode();
        StaffEdoOtp::query()->create([
            'admin_id' => $admin->id,
            'purpose' => $purpose,
            'code_hash' => $this->hashCode($code),
            'phone' => $phone,
            'expires_at' => now()->addMinutes((int) config('staff_edo.otp_ttl_minutes', 10)),
        ]);

        $channel = $this->deliverCode($admin, $code, $phone, $telegramId);
        $message = $channel === 'telegram'
            ? 'Код отправлен в Telegram.'
            : 'Код записан в журнал сервера: Telegram-бот не настроен.';
        if ((bool) config('staff_edo.reveal_otp') || $channel === 'log') {
            if ((bool) config('staff_edo.reveal_otp') || app()->environment('local')) {
                $message .= ' Код: '.$code;
            }
        }
        if ($channel === 'log' && ! (bool) config('staff_edo.reveal_otp') && ! app()->environment('local', 'testing')) {
            Log::info('Staff EDO OTP', ['admin_id' => $admin->id, 'purpose' => $purpose]);
        }

        return ['message' => $message];
    }

    public function signAgreement(Admin $admin, string $phone, ?string $telegramId, string $code, Request $request): StaffEdoAgreement
    {
        if (! $this->needsAgreement($admin) && StaffEdoAgreement::query()->where('admin_id', $admin->id)->exists()) {
            throw new RuntimeException('Соглашение уже подписано.');
        }

        $phone = $this->normalizePhone($phone);
        if ($phone === null) {
            throw new RuntimeException('Укажите телефон.');
        }
        $this->consumeOtp($admin, StaffEdoOtp::PURPOSE_AGREEMENT, $code);

        $version = (string) config('staff_edo.agreement_version');
        $hash = hash('sha256', $version.'|'.implode('|', $this->texts()));
        $html = $this->docs->agreementHtml($admin, $version, $hash);
        $path = 'staff-edo/agreements/'.$admin->id.'-'.$version.'.html';
        Storage::disk('local')->put($path, $html);

        $agreement = StaffEdoAgreement::query()->updateOrCreate(
            ['admin_id' => $admin->id],
            [
                'agreement_version' => $version,
                'phone_number' => $phone,
                'telegram_id' => $this->nullableDigits($telegramId),
                'signed_at' => now(),
                'ip_address' => (string) $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 2000),
                'otp_code_hash' => $this->hashCode($code),
                'document_hash' => $hash,
            ]
        );

        StaffEdoDocument::query()->create([
            'incident_id' => null,
            'admin_id' => $admin->id,
            'doc_type' => StaffEdoDocument::TYPE_AGREEMENT,
            'doc_title' => 'Соглашение о КЭДО '.$version,
            'storage_path' => $path,
            'doc_hash_sha256' => hash('sha256', $html),
            'signatures' => [[
                'admin_id' => $admin->id,
                'name' => $admin->name,
                'role' => $admin->role,
                'signed_at' => now()->toIso8601String(),
                'ip' => (string) $request->ip(),
                'stamp' => $hash,
            ]],
        ]);

        return $agreement;
    }

    /**
     * @param  list<\Illuminate\Http\UploadedFile>  $files
     */
    public function submitExplanation(Admin $admin, string $text, string $code, array $files, Request $request): StaffDisciplinaryIncident
    {
        $incident = $this->blockingIncident($admin);
        if (! $incident || $incident->status !== StaffDisciplinaryIncident::STATUS_DEMAND) {
            throw new RuntimeException('Нет открытого требования объяснений.');
        }
        if ($incident->deadline_at && now()->greaterThan($incident->deadline_at)) {
            throw new RuntimeException('Срок для объяснений уже истёк.');
        }

        $this->consumeOtp($admin, StaffEdoOtp::PURPOSE_EXPLANATION, $code);
        $stored = [];
        foreach ($files as $file) {
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName()) ?: 'file';
            $path = $file->storeAs('staff-edo/files/'.$incident->id, uniqid().'-'.$safe, 'local');
            $stored[] = [
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
            ];
        }

        $incident->explanation_text = trim($text);
        $incident->explanation_files = $stored;
        $incident->explanation_signed_at = now();
        $incident->status = StaffDisciplinaryIncident::STATUS_EXPLAINED;
        $incident->save();

        $html = $this->docs->explanationMemo($incident);
        $this->storeDocument(
            $incident,
            StaffEdoDocument::TYPE_EXPLANATION,
            'Объяснительная записка',
            $html,
            [[
                'admin_id' => $admin->id,
                'name' => $admin->name,
                'role' => $admin->role,
                'signed_at' => now()->toIso8601String(),
                'ip' => (string) $request->ip(),
                'otp_hash' => $this->hashCode($code),
            ]]
        );

        return $incident;
    }

    public function recordPresence(int $adminId, ?\DateTimeInterface $seenAt = null, string $source = 'face'): void
    {
        if (! Admin::query()->whereKey($adminId)->exists()) {
            throw new RuntimeException('Сотрудник не найден.');
        }

        StaffPresencePing::query()->create([
            'admin_id' => $adminId,
            'seen_at' => $seenAt ?? now(),
            'source' => $source,
        ]);
    }

    /**
     * @return array{absences: int, abandonments: int, expired: int}
     */
    public function scan(): array
    {
        return [
            'absences' => $this->scanAbsences(),
            'abandonments' => $this->scanAbandonments(),
            'expired' => $this->expireDeadlines(),
        ];
    }

    public function scanAbsences(): int
    {
        $grace = (int) config('staff_edo.absence_grace_minutes', 15);
        $cutoff = now()->subMinutes($grace);
        $created = 0;

        $bookings = ShiftSlotBooking::query()
            ->with(['slot', 'admin'])
            ->where('status', ShiftSlotBooking::STATUS_BOOKED)
            ->whereIn('kind', [ShiftSlotBooking::KIND_LEAD, ShiftSlotBooking::KIND_INTERN])
            ->whereHas('slot', function ($query) use ($cutoff) {
                $query->where('starts_at', '<=', $cutoff)->where('ends_at', '>', now());
            })
            ->get();

        foreach ($bookings as $booking) {
            if (! $booking->slot || ! $booking->admin || $booking->admin->isFired()) {
                continue;
            }
            if ($this->slotWasAccepted($booking)) {
                continue;
            }
            $exists = StaffDisciplinaryIncident::query()
                ->where('shift_slot_booking_id', $booking->id)
                ->exists();
            if ($exists) {
                continue;
            }

            $slot = $booking->slot;
            $label = $slot->starts_at?->timezone(config('app.timezone'))->format('d.m.Y H:i')
                .' — '.$slot->ends_at?->timezone(config('app.timezone'))->format('H:i');
            $this->openIncident(
                $booking->admin,
                StaffDisciplinaryIncident::TYPE_ABSENCE,
                [
                    'slot_label' => $label,
                    'booking_id' => $booking->id,
                    'grace_minutes' => $grace,
                    'note' => 'Слот начался, «Принять смену» у ресепшена не зафиксировано.',
                ],
                $booking->id,
                null
            );
            $created++;
        }

        return $created;
    }

    public function scanAbandonments(): int
    {
        $hours = (int) config('staff_edo.abandonment_hours', 4);
        $created = 0;
        $shifts = Shift::query()
            ->with('admin')
            ->where('status', '!=', 'closed')
            ->where('started_at', '<=', now()->subHours($hours))
            ->get();

        foreach ($shifts as $shift) {
            if (! $shift->admin || $shift->admin->isFired()) {
                continue;
            }
            $last = StaffPresencePing::query()
                ->where('admin_id', $shift->admin_id)
                ->where('seen_at', '>=', $shift->started_at)
                ->max('seen_at');
            if (! $last) {
                continue;
            }
            if (\Carbon\Carbon::parse($last)->greaterThan(now()->subHours($hours))) {
                continue;
            }
            $exists = StaffDisciplinaryIncident::query()
                ->where('shift_id', $shift->id)
                ->where('incident_type', StaffDisciplinaryIncident::TYPE_ABANDONMENT)
                ->exists();
            if ($exists) {
                continue;
            }

            $this->openIncident(
                $shift->admin,
                StaffDisciplinaryIncident::TYPE_ABANDONMENT,
                [
                    'slot_label' => 'Открытая смена с '.$shift->started_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
                    'shift_id' => $shift->id,
                    'last_seen_at' => (string) $last,
                    'gap_hours' => $hours,
                    'note' => 'На ресепшене нет лица дежурного администратора дольше '.$hours.' часов.',
                ],
                null,
                $shift->id
            );
            $created++;
        }

        return $created;
    }

    public function expireDeadlines(): int
    {
        $rows = StaffDisciplinaryIncident::query()
            ->where('status', StaffDisciplinaryIncident::STATUS_DEMAND)
            ->whereNotNull('demand_delivered_at')
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())
            ->get();

        $count = 0;
        foreach ($rows as $incident) {
            if (! $this->calendar->canIssueNoExplanationAct($incident->deadline_at, now())) {
                continue;
            }
            $incident->status = StaffDisciplinaryIncident::STATUS_EXPIRED;
            $incident->save();
            $html = $this->docs->noExplanationAct($incident);
            $this->storeDocument(
                $incident,
                StaffEdoDocument::TYPE_NO_EXPLANATION,
                'Акт о непредоставлении объяснений',
                $html,
                []
            );
            $count++;
        }

        return $count;
    }

    public function deliverManually(StaffDisciplinaryIncident $incident): void
    {
        if ($incident->demand_delivered_at) {
            return;
        }
        $incident->demand_delivered_at = now();
        $incident->deadline_at = $this->calendar->deadlineAfterDelivery(now());
        $incident->save();
    }

    public function canSign(Admin $actor, StaffDisciplinaryIncident $incident): bool
    {
        if ((int) $actor->id === (int) $incident->admin_id || $actor->isFired()) {
            return false;
        }
        $roleOk = in_array($actor->role, [Admin::ROLE_SUPERVISOR, Admin::ROLE_OWNER], true)
            || ($actor->role === Admin::ROLE_ADMIN && $actor->isShiftLead());
        if (! $roleOk) {
            return false;
        }

        return $this->pendingCommissionDocs($incident, $actor)->isNotEmpty();
    }

    public function signActs(Admin $actor, StaffDisciplinaryIncident $incident, Request $request): int
    {
        if (! $this->canSign($actor, $incident)) {
            throw new RuntimeException('Подписать этот акт нельзя.');
        }
        if (! StaffEdoAgreement::query()->where('admin_id', $actor->id)->exists() && $actor->hired_at) {
            throw new RuntimeException('Сначала подпишите своё соглашение о КЭДО в личном кабинете.');
        }

        $signed = 0;
        foreach ($this->pendingCommissionDocs($incident, $actor) as $doc) {
            $signatures = $doc->signatures ?? [];
            $stamp = hash('sha256', $doc->doc_hash_sha256.'|'.$actor->id.'|'.now()->toIso8601String());
            $signatures[] = [
                'admin_id' => $actor->id,
                'name' => $actor->name,
                'role' => $actor->role,
                'signed_at' => now()->toIso8601String(),
                'ip' => (string) $request->ip(),
                'stamp' => $stamp,
            ];
            $doc->signatures = $signatures;
            $doc->save();
            $signed++;
        }

        return $signed;
    }

    public function resolve(Admin $actor, StaffDisciplinaryIncident $incident, string $decision): void
    {
        if (! in_array($actor->role, [Admin::ROLE_SUPERVISOR, Admin::ROLE_OWNER], true)) {
            throw new RuntimeException('Решение принимает управляющий или владелец.');
        }
        if ((int) $actor->id === (int) $incident->admin_id) {
            throw new RuntimeException('Нельзя закрыть инцидент на себя.');
        }

        if ($decision === 'excuse') {
            if ($incident->status !== StaffDisciplinaryIncident::STATUS_EXPLAINED) {
                throw new RuntimeException('Уважительную причину можно признать только после объяснительной.');
            }
            $incident->status = StaffDisciplinaryIncident::STATUS_EXCUSED;
            $incident->resolution = 'excuse';
        } elseif ($decision === 'dismiss') {
            if (! in_array($incident->status, [
                StaffDisciplinaryIncident::STATUS_EXPLAINED,
                StaffDisciplinaryIncident::STATUS_EXPIRED,
            ], true)) {
                throw new RuntimeException('Пакет на увольнение сейчас собрать нельзя.');
            }
            $incident->status = StaffDisciplinaryIncident::STATUS_PUNISHED;
            $incident->resolution = 'dismiss';
            $this->assertCommission($incident);
            $incident->xp_forfeited = $this->forfeitReserve($incident->admin_id);
            $this->registerSfr($incident);
        } else {
            throw new RuntimeException('Неизвестное решение.');
        }

        $incident->resolved_by = $actor->id;
        $incident->resolved_at = now();
        $incident->save();
    }

    private function assertCommission(StaffDisciplinaryIncident $incident): void
    {
        $required = (int) config('staff_edo.commission_signatures', 2);
        $act = $incident->documents()->where('doc_type', StaffEdoDocument::TYPE_ABSENCE)->first();
        $count = count($act->signatures ?? []);
        if ($count < $required) {
            throw new RuntimeException('Сначала акт должны подписать '.$required.' человека комиссии.');
        }
    }

    public function exportDossier(StaffDisciplinaryIncident $incident): string
    {
        if (! in_array($incident->status, [
            StaffDisciplinaryIncident::STATUS_EXPIRED,
            StaffDisciplinaryIncident::STATUS_PUNISHED,
            StaffDisciplinaryIncident::STATUS_EXPLAINED,
        ], true)) {
            throw new RuntimeException('Архив доступен после объяснительной или истечения срока.');
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('На сервере нет расширения Zip.');
        }

        $incident->load(['documents', 'admin']);
        $dir = storage_path('app/staff-edo/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $zipPath = $dir.'/incident-'.$incident->id.'-'.uniqid().'.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Не удалось собрать архив.');
        }

        foreach ($incident->documents as $doc) {
            $absolute = Storage::disk('local')->path($doc->storage_path);
            if (is_file($absolute)) {
                $zip->addFile($absolute, $doc->doc_type.'-'.$doc->id.'.html');
            }
        }
        foreach ($incident->explanation_files ?? [] as $file) {
            $absolute = Storage::disk('local')->path((string) ($file['path'] ?? ''));
            if (is_file($absolute)) {
                $zip->addFile($absolute, 'files/'.basename((string) $file['name']));
            }
        }

        $zip->addFromString('t8-order.html', $this->docs->dismissalOrder($incident));
        $zip->addFromString('evidence.json', json_encode([
            'incident_id' => $incident->id,
            'type' => $incident->incident_type,
            'status' => $incident->status,
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'demand_delivered_at' => $incident->demand_delivered_at?->toIso8601String(),
            'deadline_at' => $incident->deadline_at?->toIso8601String(),
            'evidence' => $incident->evidence_meta,
            'xp_forfeited' => (float) $incident->xp_forfeited,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $sfr = StaffSfrEvent::query()->where('incident_id', $incident->id)->first();
        $zip->addFromString('efs-1.json', json_encode(
            $sfr?->payload ?? $this->sfrPayload($incident),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
        $zip->close();

        return $zipPath;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function journal(Admin $actor): array
    {
        if (! Schema::hasTable('staff_disciplinary_incidents')) {
            return [];
        }

        return StaffDisciplinaryIncident::query()
            ->with(['admin:id,name', 'documents'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (StaffDisciplinaryIncident $incident) use ($actor) {
                $card = $this->incidentCard($incident);
                $card['admin_name'] = $incident->admin?->name;
                $card['can_sign'] = $this->canSign($actor, $incident);
                $card['can_excuse'] = $incident->status === StaffDisciplinaryIncident::STATUS_EXPLAINED;
                $card['can_dismiss'] = in_array($incident->status, [
                    StaffDisciplinaryIncident::STATUS_EXPLAINED,
                    StaffDisciplinaryIncident::STATUS_EXPIRED,
                ], true);
                $card['can_deliver'] = $incident->demand_delivered_at === null
                    && $incident->status === StaffDisciplinaryIncident::STATUS_DEMAND;
                $card['can_export'] = in_array($incident->status, [
                    StaffDisciplinaryIncident::STATUS_EXPLAINED,
                    StaffDisciplinaryIncident::STATUS_EXPIRED,
                    StaffDisciplinaryIncident::STATUS_PUNISHED,
                ], true);
                $card['signatures'] = $incident->documents
                    ->whereIn('doc_type', StaffEdoDocument::COMMISSION_TYPES)
                    ->sum(fn (StaffEdoDocument $doc) => count($doc->signatures ?? []));
                $card['documents'] = $incident->documents->map(fn (StaffEdoDocument $doc) => [
                    'id' => $doc->id,
                    'title' => $doc->doc_title,
                    'type' => $doc->doc_type,
                ])->values()->all();

                return $card;
            })
            ->all();
    }

    public function pendingCount(): int
    {
        if (! Schema::hasTable('staff_disciplinary_incidents')) {
            return 0;
        }

        return (int) StaffDisciplinaryIncident::query()
            ->whereIn('status', [
                StaffDisciplinaryIncident::STATUS_DEMAND,
                StaffDisciplinaryIncident::STATUS_EXPLAINED,
                StaffDisciplinaryIncident::STATUS_EXPIRED,
            ])
            ->count();
    }

    public function canReadDocument(Admin $actor, StaffEdoDocument $document): bool
    {
        if ((int) $document->admin_id === (int) $actor->id) {
            return true;
        }

        return in_array($actor->role, [Admin::ROLE_SUPERVISOR, Admin::ROLE_OWNER], true);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function openIncident(Admin $admin, string $type, array $meta, ?int $bookingId, ?int $shiftId): StaffDisciplinaryIncident
    {
        $incident = StaffDisciplinaryIncident::query()->create([
            'admin_id' => $admin->id,
            'club_id' => $admin->club_id,
            'shift_id' => $shiftId,
            'shift_slot_booking_id' => $bookingId,
            'incident_type' => $type,
            'detected_at' => now(),
            'deadline_at' => null,
            'status' => StaffDisciplinaryIncident::STATUS_DEMAND,
            'evidence_meta' => $meta,
        ]);

        $this->storeDocument($incident, StaffEdoDocument::TYPE_ABSENCE, 'Акт об отсутствии', $this->docs->absenceAct($incident), []);
        $this->storeDocument($incident, StaffEdoDocument::TYPE_DEMAND, 'Требование объяснений', $this->docs->demandNotice($incident), []);

        return $incident;
    }

    /**
     * @param  list<array<string, mixed>>  $signatures
     */
    private function storeDocument(
        StaffDisciplinaryIncident $incident,
        string $type,
        string $title,
        string $html,
        array $signatures,
    ): StaffEdoDocument {
        $path = 'staff-edo/docs/'.$incident->id.'-'.$type.'-'.uniqid().'.html';
        Storage::disk('local')->put($path, $html);

        return StaffEdoDocument::query()->create([
            'incident_id' => $incident->id,
            'admin_id' => $incident->admin_id,
            'doc_type' => $type,
            'doc_title' => $title,
            'storage_path' => $path,
            'doc_hash_sha256' => hash('sha256', $html),
            'signatures' => $signatures,
        ]);
    }

    private function slotWasAccepted(ShiftSlotBooking $booking): bool
    {
        $slot = $booking->slot;
        if (! $slot) {
            return false;
        }
        $from = $slot->starts_at?->copy()->subHours(3);

        if ($booking->kind === ShiftSlotBooking::KIND_INTERN) {
            return ShiftIntern::query()
                ->where('admin_id', $booking->admin_id)
                ->where('joined_at', '>=', $from)
                ->where('joined_at', '<=', $slot->ends_at)
                ->exists();
        }

        return Shift::query()
            ->where('admin_id', $booking->admin_id)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<=', $slot->ends_at)
            ->exists();
    }

    /**
     * @return \Illuminate\Support\Collection<int, StaffEdoDocument>
     */
    private function pendingCommissionDocs(StaffDisciplinaryIncident $incident, Admin $actor)
    {
        return $incident->documents()
            ->whereIn('doc_type', StaffEdoDocument::COMMISSION_TYPES)
            ->get()
            ->filter(function (StaffEdoDocument $doc) use ($actor) {
                $ids = collect($doc->signatures ?? [])->pluck('admin_id')->map(fn ($id) => (int) $id);

                return ! $ids->contains((int) $actor->id);
            })
            ->values();
    }

    private function forfeitReserve(int $adminId): float
    {
        $year = (int) now()->year;
        $quarter = (int) ceil(now()->month / 3);
        $reserve = StaffQuarterReserve::query()->firstOrCreate(
            ['admin_id' => $adminId, 'year' => $year, 'quarter' => $quarter],
            ['points' => 0]
        );
        $points = (float) $reserve->points;
        $reserve->points = 0;
        $reserve->save();

        return $points;
    }

    private function registerSfr(StaffDisciplinaryIncident $incident): void
    {
        $payload = $this->sfrPayload($incident);
        StaffSfrEvent::query()->create([
            'incident_id' => $incident->id,
            'admin_id' => $incident->admin_id,
            'event_code' => 'UVOLNENIE',
            'reason_code' => 'п6ч1с81',
            'payload' => $payload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sfrPayload(StaffDisciplinaryIncident $incident): array
    {
        $incident->loadMissing('admin');

        return [
            'subsection' => '1.1',
            'event' => 'UVOLNENIE',
            'reason_code' => 'п6ч1с81',
            'reason_text' => 'Прогул, подпункт «а» пункта 6 части 1 статьи 81 ТК РФ',
            'employee_name' => $incident->admin?->name,
            'incident_id' => $incident->id,
            'event_date' => now()->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentCard(StaffDisciplinaryIncident $incident): array
    {
        return [
            'id' => $incident->id,
            'admin_id' => $incident->admin_id,
            'type' => $incident->incident_type,
            'type_label' => StaffDisciplinaryIncident::typeLabel($incident->incident_type),
            'status' => $incident->status,
            'status_label' => StaffDisciplinaryIncident::statusLabel($incident->status),
            'detected_at' => $incident->detected_at?->toIso8601String(),
            'deadline_at' => $incident->deadline_at?->toIso8601String(),
            'demand_delivered_at' => $incident->demand_delivered_at?->toIso8601String(),
            'explanation_text' => $incident->explanation_text,
            'slot_label' => (string) ($incident->evidence_meta['slot_label'] ?? ''),
            'signatures_required' => (int) config('staff_edo.commission_signatures', 2),
        ];
    }

    private function agreement(Admin $admin): StaffEdoAgreement
    {
        $agreement = StaffEdoAgreement::query()->where('admin_id', $admin->id)->first();
        if (! $agreement) {
            throw new RuntimeException('Сначала подпишите соглашение о КЭДО.');
        }

        return $agreement;
    }

    private function consumeOtp(Admin $admin, string $purpose, string $code): void
    {
        $otp = StaffEdoOtp::query()
            ->where('admin_id', $admin->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>=', now())
            ->orderByDesc('id')
            ->first();
        if (! $otp || ! hash_equals($otp->code_hash, $this->hashCode($code))) {
            throw new RuntimeException('Код неверный или просрочен.');
        }
        $otp->consumed_at = now();
        $otp->save();
    }

    private function makeCode(): string
    {
        $fixed = config('staff_edo.test_otp');
        if (app()->environment('testing') && is_string($fixed) && preg_match('/^\d{6}$/', $fixed)) {
            return $fixed;
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function deliverCode(Admin $admin, string $code, ?string $phone, ?string $telegramId): string
    {
        $token = trim((string) config('services.telegram.bot_token'));
        $chat = $this->nullableDigits($telegramId);
        if ($token !== '' && $chat !== null) {
            try {
                $response = Http::timeout(8)->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $chat,
                    'text' => 'Код подписи КЭДО клуба: '.$code.'. Никому не пересылайте.',
                ]);
                if ($response->ok()) {
                    return 'telegram';
                }
            } catch (\Throwable $e) {
                Log::warning('Staff EDO telegram OTP failed: '.$e->getMessage());
            }
        }

        Log::info('Staff EDO OTP fallback', [
            'admin_id' => $admin->id,
            'phone' => $phone,
            'code' => $code,
        ]);

        return 'log';
    }

    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    private function nullableDigits(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * @return array{agreement: string, kedo: string, bonus: string}
     */
    private function texts(): array
    {
        $texts = config('staff_edo.texts', []);

        return [
            'agreement' => (string) ($texts['agreement'] ?? ''),
            'kedo' => (string) ($texts['kedo'] ?? ''),
            'bonus' => (string) ($texts['bonus'] ?? ''),
        ];
    }
}
