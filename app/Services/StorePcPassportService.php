<?php

namespace App\Services;

use App\Models\StoreBuiltPc;
use App\Models\StoreWarranty;
use App\Support\ClubBrand;
use App\Support\WarrantyQr;
use Carbon\Carbon;

class StorePcPassportService
{
    public function __construct(private StoreWarrantyService $warranties) {}

    /**
     * @return array<string, mixed>
     */
    public function present(StoreWarranty $warranty): array
    {
        $token = $this->warranties->ensurePublicToken($warranty);
        $warranty->loadMissing([
            'club:id,name',
            'builtPc.assembler:id,name,role',
            'builtPc.componentLinks.component',
        ]);

        $pc = $warranty->builtPc;
        $items = is_array($warranty->build_snapshot) ? array_values($warranty->build_snapshot) : [];
        if ($items === [] && $pc) {
            $items = $this->warranties->buildSnapshot($pc);
        }
        $items = $this->warranties->enrichBuildItems($items, $pc);
        $buildRemaining = $this->warranties->remainingWarranty($warranty->ends_at);

        $parts = array_map(function (array $row) {
            return [
                'type' => $row['type'] ?? 'other',
                'type_label' => $row['type_label'] ?? '—',
                'name' => $row['name'] ?? '',
                'serials' => is_array($row['serials'] ?? null) ? array_values($row['serials']) : [],
                'serial_label' => $row['warranty_number'] ?? null,
                'status' => $row['component_status'] ?? null,
                'in_repair' => ($row['component_status'] ?? null) === 'repair',
                'repair_label' => $row['sent_to_repair_label'] ?? null,
                'warranty_months' => $row['warranty_months'] ?? null,
                'warranty_state' => $row['warranty_state'] ?? 'none',
                'warranty_label' => $row['warranty_label'] ?? null,
                'warranty_days_left' => $row['warranty_days_left'] ?? null,
            ];
        }, $items);

        $video = $this->videoState($pc, $token);
        $assembler = $this->publicPerson($pc?->assembler?->name);

        return [
            'title' => $warranty->product_name ?: ($pc?->title ?: 'Сборка ПК'),
            'serial' => $warranty->serial,
            'club' => ClubBrand::nameForClub($warranty->club),
            'assembler' => $assembler,
            'status' => $warranty->status,
            'warranty_state' => $buildRemaining['state'],
            'warranty_label' => $buildRemaining['label'],
            'warranty_months' => $warranty->warranty_months,
            'started_at' => $warranty->started_at?->format('d.m.Y'),
            'ends_at' => $warranty->ends_at?->format('d.m.Y'),
            'verified' => (bool) $pc?->verified_ok,
            'parts' => $parts,
            'timeline' => $this->timeline($warranty, $pc, $parts, $video, $assembler),
            'video' => $video,
            'passport_url' => WarrantyQr::payload($warranty),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @param  array<string, mixed>  $video
     * @return list<array{at:?string,at_iso:?string,title:string,detail:?string,kind:string}>
     */
    private function timeline(
        StoreWarranty $warranty,
        ?StoreBuiltPc $pc,
        array $parts,
        array $video,
        ?string $assembler,
    ): array {
        $events = [];

        $push = function (?Carbon $at, string $title, ?string $detail, string $kind) use (&$events) {
            $events[] = [
                'at' => $at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
                'at_iso' => $at?->toIso8601String(),
                'sort' => $at?->timestamp ?? 0,
                'title' => $title,
                'detail' => $detail,
                'kind' => $kind,
            ];
        };

        if ($pc?->assembly_started_at) {
            $who = $assembler ? 'Стол сборки, '.$assembler : 'Стол сборки';
            $push($pc->assembly_started_at, 'Сборка начата', $who, 'start');
        } elseif ($pc?->created_at) {
            $push($pc->created_at, 'Сборка создана', $assembler ? 'Сборщик: '.$assembler : null, 'start');
        }

        if ($pc?->verified_at) {
            $push(
                $pc->verified_at,
                $pc->verified_ok ? 'Серийники сверены с железом' : 'Сверка сборки с расхождениями',
                $pc->verified_hostname ? 'Хост '.$pc->verified_hostname : null,
                'verify'
            );
        }

        if ($pc?->assembly_finished_at) {
            $push($pc->assembly_finished_at, 'Сборка завершена', 'Камера стола · NVR', 'done');
        }

        if ($pc?->sold_at) {
            $push($pc->sold_at, 'ПК выдан покупателю', 'Гарантия открыта', 'sold');
        } elseif ($warranty->started_at) {
            $push(Carbon::parse($warranty->started_at)->startOfDay(), 'Гарантия открыта', $warranty->ends_at?->format('до d.m.Y'), 'sold');
        }

        if ($video['ready']) {
            $push($pc?->assembly_clip_uploaded_at, 'Видео сборки готово', 'Запись с камеры стола', 'video');
        }

        foreach ($parts as $part) {
            if (! empty($part['in_repair']) && ! empty($part['repair_label'])) {
                $at = null;
                foreach ($pc?->componentLinks ?? [] as $link) {
                    if ((string) ($link->name ?? '') === (string) ($part['name'] ?? '') && $link->component?->sent_to_repair_at) {
                        $at = $link->component->sent_to_repair_at;
                        break;
                    }
                }
                $push($at, 'В ремонте: '.($part['type_label'] ?? 'деталь'), $part['name'] ?? null, 'repair');
            }
        }

        usort($events, fn ($a, $b) => ($a['sort'] <=> $b['sort']));

        return array_map(function (array $row) {
            unset($row['sort']);

            return $row;
        }, $events);
    }

    /**
     * @return array{ready:bool,pending:bool,url:?string,hint:string}
     */
    private function videoState(?StoreBuiltPc $pc, string $token): array
    {
        if ($pc && $pc->hasAssemblyClip()) {
            return [
                'ready' => true,
                'pending' => false,
                'url' => url('/pc/'.$token.'/video'),
                'hint' => 'Запись с камеры стола сборщика',
            ];
        }

        if ($pc && ($pc->assembly_started_at || $pc->assembly_finished_at)) {
            return [
                'ready' => false,
                'pending' => true,
                'url' => null,
                'hint' => 'Запись с камеры стола выгружается с регистратора. Обновите страницу чуть позже.',
            ];
        }

        return [
            'ready' => false,
            'pending' => false,
            'url' => null,
            'hint' => 'Видео сборки появится, когда сборщик начнёт работу за столом с камерой.',
        ];
    }

    private function publicPerson(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $first = preg_split('/\s+/u', $name)[0] ?? $name;

        return $first !== '' ? $first : null;
    }
}
