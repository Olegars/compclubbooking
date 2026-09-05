<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\StaffEmploymentProfile;
use App\Support\StaffEmploymentRules;
use App\Support\StaffFireSafetyRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StaffEmploymentService
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Admin $admin): array
    {
        $profile = $this->profile($admin);
        $accepted = $profile->acceptedRuleIds();
        $fireAccepted = $profile->acceptedFireRuleIds();

        return [
            'required' => $admin->needsEmployment(),
            'status' => $profile->status ?: StaffEmploymentProfile::STATUS_DRAFT,
            'rejection_reason' => $profile->isRejected() ? $profile->rejection_reason : null,
            'appointment_at' => $profile->appointment_at?->toIso8601String(),
            'rules' => StaffEmploymentRules::all(),
            'accepted_ids' => $accepted,
            'rules_complete' => $this->rulesComplete($accepted),
            'fire_rules' => StaffFireSafetyRules::all(),
            'accepted_fire_ids' => $fireAccepted,
            'fire_rules_complete' => $this->fireRulesComplete($fireAccepted),
            'profile' => [
                'full_name' => $profile->full_name ?: $admin->name,
                'passport_series' => $profile->passport_series,
                'passport_number' => $profile->passport_number,
                'issued_by' => $profile->issued_by,
                'issued_at' => $profile->issued_at?->toDateString(),
                'department_code' => $profile->department_code,
                'birth_date' => $profile->birth_date?->toDateString(),
                'has_scan' => filled($profile->passport_scan_path),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function staffCard(Admin $employee): ?array
    {
        $profile = $employee->employmentProfile;
        if (! $profile && ! $employee->needsEmployment()) {
            return null;
        }

        if (! $profile) {
            return [
                'status' => StaffEmploymentProfile::STATUS_DRAFT,
                'submitted_at' => null,
                'rejection_reason' => null,
                'has_scan' => false,
                'appointment_at' => null,
            ];
        }

        $ext = strtolower(pathinfo((string) $profile->passport_scan_path, PATHINFO_EXTENSION));

        $card = [
            'status' => $profile->status ?: StaffEmploymentProfile::STATUS_DRAFT,
            'submitted_at' => $profile->submitted_at?->toIso8601String(),
            'rejection_reason' => $profile->rejection_reason,
            'has_scan' => filled($profile->passport_scan_path),
            'scan_kind' => $ext === 'pdf' ? 'pdf' : 'image',
            'appointment_at' => $profile->appointment_at?->toIso8601String(),
        ];

        if ($profile->isInPipeline() || $profile->isRejected()) {
            $card['full_name'] = $profile->full_name;
            $card['passport_series'] = $profile->passport_series;
            $card['passport_number'] = $profile->passport_number;
            $card['issued_by'] = $profile->issued_by;
            $card['issued_at'] = $profile->issued_at?->toDateString();
            $card['department_code'] = $profile->department_code;
            $card['birth_date'] = $profile->birth_date?->toDateString();
        }

        return $card;
    }

    public function acceptRule(Admin $admin, int $ruleId): void
    {
        $this->assertEditable($admin);

        if (! in_array($ruleId, StaffEmploymentRules::ids(), true)) {
            throw new RuntimeException('Такого правила нет.');
        }

        $profile = $this->profile($admin);
        $ids = $profile->acceptedRuleIds();
        if (! in_array($ruleId, $ids, true)) {
            $ids[] = $ruleId;
            sort($ids);
        }

        $profile->update(['accepted_rule_ids' => $ids]);
    }

    public function acceptFireRule(Admin $admin, int $ruleId): void
    {
        $this->assertPending($admin);

        $profile = $this->profile($admin);
        if (! $profile->needsFireSafety()) {
            throw new RuntimeException('Сначала нужно пройти биометрию в клубе.');
        }

        if (! in_array($ruleId, StaffFireSafetyRules::ids(), true)) {
            throw new RuntimeException('Такого правила нет.');
        }

        $ids = $profile->acceptedFireRuleIds();
        if (! in_array($ruleId, $ids, true)) {
            $ids[] = $ruleId;
            sort($ids);
        }

        $profile->update(['accepted_fire_rule_ids' => $ids]);

        if ($this->fireRulesComplete($ids)) {
            $this->hireApplicant($admin, $profile);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveProfile(Admin $admin, array $data, ?UploadedFile $scan = null): StaffEmploymentProfile
    {
        $this->assertEditable($admin);

        $profile = $this->profile($admin);
        $profile->fill([
            'full_name' => $data['full_name'],
            'passport_series' => $data['passport_series'],
            'passport_number' => $data['passport_number'],
            'issued_by' => $data['issued_by'],
            'issued_at' => $data['issued_at'],
            'department_code' => $data['department_code'],
            'birth_date' => $data['birth_date'],
        ]);

        if ($scan) {
            if ($profile->passport_scan_path) {
                Storage::disk('local')->delete($profile->passport_scan_path);
            }
            $ext = strtolower($scan->getClientOriginalExtension() ?: 'jpg');
            $path = $scan->storeAs('employment/'.$admin->id, 'passport.'.$ext, 'local');
            $profile->passport_scan_path = $path;
        }

        $profile->save();

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(Admin $admin, array $data, ?UploadedFile $scan = null): void
    {
        $profile = $this->saveProfile($admin, $data, $scan);

        if (! $this->rulesComplete($profile->acceptedRuleIds())) {
            throw new RuntimeException('Нужно принять все правила.');
        }

        if (! filled($profile->passport_scan_path)) {
            throw new RuntimeException('Загрузите скан паспорта.');
        }

        $profile->update([
            'status' => StaffEmploymentProfile::STATUS_REVIEW,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'rejection_reason' => null,
            'appointment_at' => null,
            'biometrics_captured_at' => null,
            'biometrics_payload' => null,
            'accepted_fire_rule_ids' => [],
        ]);

        $admin->update([
            'name' => $profile->full_name,
            'role' => Admin::ROLE_INTERN,
        ]);
    }

    public function scheduleAppointment(Admin $reviewer, Admin $applicant, string $appointmentAt): void
    {
        $this->assertReviewer($reviewer);
        $this->assertPendingApplicant($applicant);

        $profile = $this->profile($applicant);
        if (! $profile->isOnReview()) {
            throw new RuntimeException('Сначала анкета должна быть на проверке.');
        }

        $profile->update([
            'status' => StaffEmploymentProfile::STATUS_INVITED,
            'appointment_at' => $appointmentAt,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
            'rejection_reason' => null,
        ]);
    }

    public function captureBiometrics(Admin $reviewer, Admin $applicant): void
    {
        $this->assertReviewer($reviewer);
        $this->assertPendingApplicant($applicant);

        $profile = $this->profile($applicant);
        if (! $profile->isInvited()) {
            throw new RuntimeException('Сначала назначьте дату визита.');
        }

        $profile->update([
            'status' => StaffEmploymentProfile::STATUS_FIRE_SAFETY,
            'biometrics_captured_at' => now(),
            'biometrics_payload' => [
                'stub' => true,
                'camera' => 'club-front',
                'note' => 'Заглушка биометрии: съёмка с камеры клуба ещё не подключена.',
                'captured_at' => now()->toIso8601String(),
                'reviewer_id' => $reviewer->id,
            ],
            'reviewed_by' => $reviewer->id,
        ]);
    }

    public function reject(Admin $reviewer, Admin $applicant, string $reason): void
    {
        $this->assertReviewer($reviewer);

        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new RuntimeException('Укажите причину отклонения.');
        }

        $this->assertPendingApplicant($applicant);

        $profile = $this->profile($applicant);
        if (! in_array($profile->status, [
            StaffEmploymentProfile::STATUS_REVIEW,
            StaffEmploymentProfile::STATUS_INVITED,
        ], true)) {
            throw new RuntimeException('На этом шаге анкету уже нельзя отклонить.');
        }

        $profile->update([
            'status' => StaffEmploymentProfile::STATUS_REJECTED,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
            'rejection_reason' => $reason,
            'appointment_at' => null,
        ]);
    }

    public function profile(Admin $admin): StaffEmploymentProfile
    {
        return StaffEmploymentProfile::query()->firstOrCreate(
            ['admin_id' => $admin->id],
            [
                'full_name' => $admin->name,
                'accepted_rule_ids' => [],
                'accepted_fire_rule_ids' => [],
                'status' => StaffEmploymentProfile::STATUS_DRAFT,
            ]
        );
    }

    /**
     * @param  list<int>  $accepted
     */
    public function rulesComplete(array $accepted): bool
    {
        $need = StaffEmploymentRules::ids();

        return count(array_intersect($need, $accepted)) === count($need);
    }

    /**
     * @param  list<int>  $accepted
     */
    public function fireRulesComplete(array $accepted): bool
    {
        $need = StaffFireSafetyRules::ids();

        return count(array_intersect($need, $accepted)) === count($need);
    }

    private function hireApplicant(Admin $admin, StaffEmploymentProfile $profile): void
    {
        $profile->update([
            'status' => StaffEmploymentProfile::STATUS_APPROVED,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        $admin->update([
            'name' => $profile->full_name ?: $admin->name,
            'employment_pending' => false,
            'hired_at' => now(),
            'role' => Admin::ROLE_INTERN,
            'pay_type' => $admin->pay_type ?: 'shift',
        ]);
    }

    private function assertPending(Admin $admin): void
    {
        if (! $admin->needsEmployment()) {
            throw new RuntimeException('Анкета устройства уже заполнена.');
        }
    }

    private function assertPendingApplicant(Admin $applicant): void
    {
        if (! $applicant->needsEmployment()) {
            throw new RuntimeException('Анкета уже рассмотрена.');
        }
    }

    private function assertEditable(Admin $admin): void
    {
        $this->assertPending($admin);

        $profile = $this->profile($admin);
        if (! $profile->canEdit()) {
            throw new RuntimeException('Анкета на проверке — дождитесь решения.');
        }
    }

    private function assertReviewer(Admin $reviewer): void
    {
        if (! $reviewer->canReviewEmployment()) {
            throw new RuntimeException('Проверяет только владелец или управляющий.');
        }
    }
}
