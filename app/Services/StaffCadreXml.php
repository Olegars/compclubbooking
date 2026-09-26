<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Пакет выгрузки: подраздел 1.1 ЕФС-1 и персонифицированные сведения КНД 1151162.
 * Перед записью файла XML проходит локальную XSD. Это обменный состав полей кабинета,
 * его сверяют с актуальным альбомом СФР и ФНС перед отправкой.
 */
class StaffCadreXml
{
    /**
     * @param  array<string, mixed>  $employer
     * @param  list<array<string, mixed>>  $people
     */
    public function efs1(array $employer, array $people): string
    {
        $dom = $this->document();
        $root = $dom->createElement('ЭДПФР');
        $dom->appendChild($root);
        $root->appendChild($dom->createComment(' Подраздел 1.1 ЕФС-1. Сверьте файл с альбомом форматов СФР перед отправкой. '));

        $form = $this->el($dom, 'ЕФС-1');
        $root->appendChild($form);

        $insurer = $this->el($dom, 'Страхователь');
        $insurer->appendChild($this->el($dom, 'РегНомер', (string) $employer['sfr_reg_number']));
        $insurer->appendChild($this->el($dom, 'Наименование', (string) $employer['name']));
        $insurer->appendChild($this->el($dom, 'ИНН', (string) $employer['inn']));
        if (filled($employer['kpp'] ?? null)) {
            $insurer->appendChild($this->el($dom, 'КПП', (string) $employer['kpp']));
        }
        $form->appendChild($insurer);

        foreach ($people as $person) {
            $block = $this->el($dom, 'СЗВ');
            $personNode = $this->el($dom, 'ЗЛ');
            $personNode->appendChild($this->el($dom, 'СНИЛС', (string) $person['snils']));
            $personNode->appendChild($this->el($dom, 'ИНН', (string) $person['inn']));
            $personNode->appendChild($this->el($dom, 'Фамилия', (string) $person['last']));
            $personNode->appendChild($this->el($dom, 'Имя', (string) $person['first']));
            if (filled($person['middle'] ?? null)) {
                $personNode->appendChild($this->el($dom, 'Отчество', (string) $person['middle']));
            }
            $personNode->appendChild($this->el($dom, 'ДатаРождения', (string) $person['birth_date']));
            $personNode->appendChild($this->el($dom, 'Пол', (string) $person['gender_code']));
            $block->appendChild($personNode);

            foreach ($person['events'] as $event) {
                $row = $this->el($dom, 'Мероприятие');
                $row->appendChild($this->el($dom, 'Дата', (string) $event['date']));
                $row->appendChild($this->el($dom, 'Вид', (string) $event['kind']));
                $row->appendChild($this->el($dom, 'Должность', (string) $event['title']));
                $row->appendChild($this->el($dom, 'КодОКЗ', (string) $event['okz']));
                $row->appendChild($this->el($dom, 'НомерПриказа', (string) $event['order_number']));
                $row->appendChild($this->el($dom, 'ДатаПриказа', (string) $event['order_date']));
                if (filled($event['part_time'] ?? null)) {
                    $row->appendChild($this->el($dom, 'НеполноеВремя', (string) $event['part_time']));
                }
                if (filled($event['fire_reason'] ?? null)) {
                    $row->appendChild($this->el($dom, 'ПричинаУвольнения', (string) $event['fire_reason']));
                }
                $block->appendChild($row);
            }

            $form->appendChild($block);
        }

        $form->appendChild($this->el($dom, 'ДатаЗаполнения', now()->toDateString()));
        $this->assertSchema($dom, (string) config('staff_cadre.xsd.efs1'));

        return $dom->saveXML() ?: '';
    }

    /**
     * @param  array<string, mixed>  $employer
     * @param  list<array<string, mixed>>  $rows
     */
    public function persRecords(array $employer, int $year, int $month, array $rows): string
    {
        $dom = $this->document();
        $fileId = $this->fileId($employer, $year, $month);
        $root = $dom->createElement('Файл');
        $root->setAttribute('ИдФайл', $fileId);
        $root->setAttribute('ВерсПрог', 'CompClub');
        $root->setAttribute('ВерсФорм', '5.01');
        $dom->appendChild($root);

        $doc = $dom->createElement('Документ');
        $doc->setAttribute('КНД', '1151162');
        $doc->setAttribute('ДатаДок', now()->format('d.m.Y'));
        $doc->setAttribute('Период', str_pad((string) $month, 2, '0', STR_PAD_LEFT));
        $doc->setAttribute('ОтчетГод', (string) $year);
        $doc->setAttribute('КодНО', (string) $employer['tax_office']);
        $root->appendChild($doc);

        $np = $this->el($dom, 'СвНП');
        $np->appendChild($this->el($dom, 'НаимОрг', (string) $employer['name']));
        $np->appendChild($this->el($dom, 'ИНН', (string) $employer['inn']));
        if (filled($employer['kpp'] ?? null)) {
            $np->appendChild($this->el($dom, 'КПП', (string) $employer['kpp']));
        }
        $doc->appendChild($np);

        foreach ($rows as $row) {
            $person = $dom->createElement('ПерсСвФЛ');
            $person->setAttribute('ИННФЛ', (string) $row['inn']);
            $person->setAttribute('СНИЛС', (string) $row['snils']);
            $person->setAttribute('СумВыпл', number_format((float) $row['amount'], 2, '.', ''));
            $person->appendChild($this->el($dom, 'Фамилия', (string) $row['last']));
            $person->appendChild($this->el($dom, 'Имя', (string) $row['first']));
            if (filled($row['middle'] ?? null)) {
                $person->appendChild($this->el($dom, 'Отчество', (string) $row['middle']));
            }
            $doc->appendChild($person);
        }

        $this->assertSchema($dom, (string) config('staff_cadre.xsd.pers'));

        $xml = $dom->saveXML() ?: '';
        $encoded = mb_convert_encoding($xml, 'Windows-1251', 'UTF-8');
        if ($encoded === false) {
            throw new RuntimeException('Не удалось собрать XML в кодировке windows-1251.');
        }

        return str_replace('encoding="UTF-8"', 'encoding="windows-1251"', $encoded);
    }

    private function fileId(array $employer, int $year, int $month): string
    {
        $office = preg_replace('/\W+/', '', (string) $employer['tax_office']) ?: '0000';
        $inn = (string) $employer['inn'];
        $kpp = (string) ($employer['kpp'] ?? '');
        $stamp = now()->format('Ymd');
        $guid = str_replace('-', '', (string) str()->uuid());

        return 'NO_PERSSVFL_'.$office.'_'.$office.'_'.$inn.$kpp.'_'.$stamp.'_'.$guid.'_'.$year.str_pad((string) $month, 2, '0', STR_PAD_LEFT);
    }

    private function document(): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        return $dom;
    }

    private function el(DOMDocument $dom, string $name, ?string $text = null): DOMElement
    {
        $node = $dom->createElement($name);
        if ($text !== null && $text !== '') {
            $node->appendChild($dom->createTextNode($text));
        }

        return $node;
    }

    private function assertSchema(DOMDocument $dom, string $xsdPath): void
    {
        if (! is_file($xsdPath)) {
            throw new RuntimeException('Нет XSD-схемы для проверки выгрузки.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $ok = $dom->schemaValidate($xsdPath);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($ok) {
            return;
        }

        $message = collect($errors)->map(fn ($error) => trim($error->message))->filter()->implode('; ');
        throw new RuntimeException('XML не прошёл проверку схемы: '.($message ?: 'неизвестная ошибка'));
    }
}
