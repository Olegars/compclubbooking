<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\StaffDisciplinaryIncident;
use App\Models\StaffEdoDocument;

class StaffEdoDocumentRenderer
{
    public function agreementHtml(Admin $admin, string $version, string $hash): string
    {
        $texts = config('staff_edo.texts', []);
        $body = $this->sections([
            'Соглашение о ПЭП' => (string) ($texts['agreement'] ?? ''),
            'Положение о КЭДО' => (string) ($texts['kedo'] ?? ''),
            'Положение о премировании и слотах' => (string) ($texts['bonus'] ?? ''),
        ]);

        return $this->page(
            'Соглашение о КЭДО '.$version,
            '<p>Сотрудник: '.$this->e($admin->name).'</p>'.$body.$this->stamp('Хеш документов', $hash)
        );
    }

    public function absenceAct(StaffDisciplinaryIncident $incident): string
    {
        $incident->loadMissing('admin');
        $meta = $incident->evidence_meta ?? [];
        $when = $incident->detected_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');

        return $this->page(
            'Акт об отсутствии на рабочем месте',
            '<p>Сотрудник: '.$this->e($incident->admin?->name).'</p>'
            .'<p>Вид: '.$this->e(StaffDisciplinaryIncident::typeLabel($incident->incident_type)).'</p>'
            .'<p>Зафиксировано: '.$this->e((string) $when).'</p>'
            .'<p>Слот: '.$this->e((string) ($meta['slot_label'] ?? '—')).'</p>'
            .'<p>Основание: '.$this->e((string) ($meta['note'] ?? '')).'</p>'
            .$this->stamp('Метаданные', json_encode($meta, JSON_UNESCAPED_UNICODE))
        );
    }

    public function demandNotice(StaffDisciplinaryIncident $incident): string
    {
        $incident->loadMissing('admin');
        $when = $incident->detected_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');

        return $this->page(
            'Требование о письменных объяснениях',
            '<p>'.$this->e($incident->admin?->name).', в соответствии со статьёй 193 Трудового кодекса РФ '
            .'предоставьте письменные объяснения по факту отсутствия на рабочем месте '
            .$this->e((string) $when).'.</p>'
            .'<p>Срок — два рабочих дня с момента вручения этого требования в личном кабинете. '
            .'День вручения не считается. Выходные и нерабочие праздники в срок не входят.</p>'
        );
    }

    public function explanationMemo(StaffDisciplinaryIncident $incident): string
    {
        $incident->loadMissing('admin');
        $files = collect($incident->explanation_files ?? [])->pluck('name')->implode(', ');

        return $this->page(
            'Объяснительная записка работника',
            '<p>Сотрудник: '.$this->e($incident->admin?->name).'</p>'
            .'<p>Подписано: '.$this->e((string) $incident->explanation_signed_at?->timezone(config('app.timezone'))->format('d.m.Y H:i')).'</p>'
            .'<p>Текст:</p><p>'.nl2br($this->e((string) $incident->explanation_text)).'</p>'
            .'<p>Файлы: '.$this->e($files !== '' ? $files : 'нет').'</p>'
        );
    }

    public function noExplanationAct(StaffDisciplinaryIncident $incident): string
    {
        $incident->loadMissing('admin');
        $deadline = $incident->deadline_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');

        return $this->page(
            'Акт о непредоставлении письменных объяснений',
            '<p>Сотрудник '.$this->e($incident->admin?->name).' не предоставил письменные объяснения '
            .'в срок до '.$this->e((string) $deadline).'.</p>'
            .'<p>Акт составлен на следующий календарный день после истечения срока, установленного статьёй 193 ТК РФ.</p>'
        );
    }

    public function dismissalOrder(StaffDisciplinaryIncident $incident): string
    {
        $incident->loadMissing('admin');
        $entity = (string) config('club.legal.entity', '');
        $name = $incident->admin?->name ?: '—';
        $role = $incident->admin?->roleLabel() ?: '—';
        $date = now()->timezone(config('app.timezone'))->format('d.m.Y');

        return $this->page(
            'Приказ (распоряжение) о прекращении трудового договора. Форма Т-8',
            '<p>Организация: '.$this->e($entity !== '' ? $entity : 'реквизиты клуба в CLUB_LEGAL_ENTITY').'</p>'
            .'<p>Номер: Т-8-'.$incident->id.' от '.$this->e($date).'</p>'
            .'<p>Прекратить трудовой договор с '.$this->e($name).', должность '.$this->e($role).'.</p>'
            .'<p>Основание: трудовой договор расторгнут по инициативе работодателя в связи с прогулом, '
            .'подпункт «а» пункта 6 части 1 статьи 81 Трудового кодекса Российской Федерации.</p>'
            .'<p>Документы-основания: акты и требование из этого архива, инцидент №'.$incident->id.'.</p>'
            .'<p>Подпись работодателя: _____________ &nbsp;&nbsp; Дата: _____________</p>'
            .'<p>С приказом ознакомлен: _____________ &nbsp;&nbsp; Дата: _____________</p>'
            .'<p>Эта форма печатается и подписывается на бумаге. Электронный архив её не заменяет.</p>'
        );
    }

    /**
     * @param  array<string, string>  $sections
     */
    private function sections(array $sections): string
    {
        $html = '';
        foreach ($sections as $title => $text) {
            $html .= '<h2>'.$this->e($title).'</h2><p>'.nl2br($this->e($text)).'</p>';
        }

        return $html;
    }

    private function stamp(string $label, ?string $value): string
    {
        return '<p><strong>'.$this->e($label).':</strong> '.$this->e((string) $value).'</p>';
    }

    private function page(string $title, string $body): string
    {
        return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
            .'<title>'.$this->e($title).'</title>'
            .'<style>body{font-family:Georgia,serif;max-width:720px;margin:32px auto;color:#111;line-height:1.45}'
            .'h1{font-size:20px}h2{font-size:16px;margin-top:24px}@media print{body{margin:12mm}}</style>'
            .'</head><body><h1>'.$this->e($title).'</h1>'.$body
            .'<p><button onclick="print()">Печать / PDF</button></p></body></html>';
    }

    private function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
