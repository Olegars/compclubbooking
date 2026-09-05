<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\StaffEmploymentProfile;
use App\Support\StaffEmploymentRules;
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

        return [
            'required' => $admin->needsEmployment(),
            'rules' => StaffEmploymentRules::all(),
            'accepted_ids' => $accepted,
            'rules_complete' => $this->rulesComplete($accepted),
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

    public function acceptRule(Admin $admin, int $ruleId): void
    {
        $this->assertPending($admin);

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

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveProfile(Admin $admin, array $data, ?UploadedFile $scan = null): StaffEmploymentProfile
    {
        $this->assertPending($admin);

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
    public function hire(Admin $admin, array $data, ?UploadedFile $scan = null): void
    {
        $this->assertPending($admin);

        $profile = $this->saveProfile($admin, $data, $scan);

        if (! $this->rulesComplete($profile->acceptedRuleIds())) {
            throw new RuntimeException('Нужно принять все правила.');
        }

        if (! filled($profile->passport_scan_path)) {
            throw new RuntimeException('Загрузите скан паспорта.');
        }

        $admin->update([
            'name' => $profile->full_name,
            'employment_pending' => false,
            'hired_at' => now(),
            'role' => Admin::ROLE_INTERN,
            'pay_type' => $admin->pay_type ?: 'shift',
        ]);
    }

    public function profile(Admin $admin): StaffEmploymentProfile
    {
        return StaffEmploymentProfile::query()->firstOrCreate(
            ['admin_id' => $admin->id],
            [
                'full_name' => $admin->name,
                'accepted_rule_ids' => [],
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

    private function assertPending(Admin $admin): void
    {
        if (! $admin->needsEmployment()) {
            throw new RuntimeException('Анкета устройства уже заполнена.');
        }
    }
}
