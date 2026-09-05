<?php

namespace App\Services;

use App\Models\StaffDocument;
use App\Models\StaffDocumentSection;
use App\Support\StaffEmploymentRules;
use App\Support\StaffFireSafetyRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class StaffDocumentService
{
    public function ensureSeeded(): void
    {
        $this->seedSystem(
            StaffDocument::SLUG_EMPLOYMENT,
            StaffDocument::KIND_EMPLOYMENT,
            'Условия работы администратора',
            1,
            StaffEmploymentRules::defaults(),
        );
        $this->seedSystem(
            StaffDocument::SLUG_FIRE_SAFETY,
            StaffDocument::KIND_FIRE_SAFETY,
            'Техника пожарной безопасности',
            2,
            StaffFireSafetyRules::defaults(),
        );
    }

    /**
     * @return list<array{id: int, title: string, kind: string, slug: string, is_system: bool, sort_order: int, sections: list<array{id: int, title: string, body: string, sort_order: int}>}>
     */
    public function configPayload(): array
    {
        $this->ensureSeeded();

        return StaffDocument::query()
            ->with('sections')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (StaffDocument $doc) => $this->serializeDocument($doc))
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, title: string, body: string}>
     */
    public function sectionsFor(string $kind): array
    {
        $this->ensureSeeded();

        return StaffDocument::query()
            ->where('kind', $kind)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with('sections')
            ->get()
            ->flatMap(fn (StaffDocument $doc) => $doc->sections)
            ->map(fn (StaffDocumentSection $section) => [
                'id' => $section->id,
                'title' => $section->title,
                'body' => $section->body,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public function sectionIdsFor(string $kind): array
    {
        return array_column($this->sectionsFor($kind), 'id');
    }

    public function titleFor(string $kind): string
    {
        $this->ensureSeeded();

        $title = StaffDocument::query()
            ->where('kind', $kind)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('title');

        return is_string($title) && $title !== ''
            ? $title
            : ($kind === StaffDocument::KIND_FIRE_SAFETY
                ? 'Техника пожарной безопасности'
                : 'Условия работы администратора');
    }

    /**
     * @param  array{title: string, kind: string, sections: list<array{id?: int|null, title: string, body: string}>}  $data
     */
    public function saveDocument(?StaffDocument $document, array $data): StaffDocument
    {
        $this->ensureSeeded();

        $title = trim($data['title']);
        $kind = $data['kind'];
        $sections = $data['sections'];

        if (! in_array($kind, StaffDocument::KINDS, true)) {
            throw new RuntimeException('Неизвестный тип документа.');
        }

        if ($title === '') {
            throw new RuntimeException('Укажите заголовок документа.');
        }

        if ($sections === []) {
            throw new RuntimeException('Добавьте хотя бы один раздел.');
        }

        foreach ($sections as $index => $section) {
            if (trim((string) ($section['title'] ?? '')) === '') {
                throw new RuntimeException('У раздела '.($index + 1).' нет заголовка.');
            }
            if (trim((string) ($section['body'] ?? '')) === '') {
                throw new RuntimeException('У раздела «'.trim((string) $section['title']).'» нет текста.');
            }
        }

        return DB::transaction(function () use ($document, $title, $kind, $sections) {
            if (! $document) {
                $document = new StaffDocument([
                    'slug' => $this->uniqueSlug($title),
                    'kind' => $kind,
                    'title' => $title,
                    'sort_order' => (int) StaffDocument::query()->max('sort_order') + 1,
                    'is_system' => false,
                ]);
            } else {
                $document->title = $title;
                if (! $document->isSystem()) {
                    $document->kind = $kind;
                }
            }

            $document->save();

            $keepIds = [];
            foreach (array_values($sections) as $index => $row) {
                $sectionId = isset($row['id']) ? (int) $row['id'] : 0;
                $payload = [
                    'title' => trim((string) $row['title']),
                    'body' => trim((string) $row['body']),
                    'sort_order' => $index + 1,
                ];

                if ($sectionId > 0) {
                    $section = StaffDocumentSection::query()
                        ->where('document_id', $document->id)
                        ->whereKey($sectionId)
                        ->first();
                    if (! $section) {
                        throw new RuntimeException('Раздел не найден.');
                    }
                    $section->update($payload);
                    $keepIds[] = $section->id;
                    continue;
                }

                $created = $document->sections()->create($payload);
                $keepIds[] = $created->id;
            }

            StaffDocumentSection::query()
                ->where('document_id', $document->id)
                ->whereNotIn('id', $keepIds)
                ->delete();

            return $document->refresh()->load('sections');
        });
    }

    public function deleteDocument(StaffDocument $document): void
    {
        if ($document->isSystem()) {
            throw new RuntimeException('Системный документ нельзя удалить.');
        }

        $document->delete();
    }

    /**
     * @param  list<array{title: string, body: string}>  $sections
     */
    private function seedSystem(string $slug, string $kind, string $title, int $sortOrder, array $sections): void
    {
        $document = StaffDocument::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'kind' => $kind,
                'title' => $title,
                'sort_order' => $sortOrder,
                'is_system' => true,
            ]
        );

        if ($document->sections()->exists()) {
            return;
        }

        foreach ($sections as $index => $section) {
            $document->sections()->create([
                'title' => $section['title'],
                'body' => $section['body'],
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * @return array{id: int, title: string, kind: string, slug: string, is_system: bool, sort_order: int, sections: list<array{id: int, title: string, body: string, sort_order: int}>}
     */
    private function serializeDocument(StaffDocument $doc): array
    {
        return [
            'id' => $doc->id,
            'title' => $doc->title,
            'kind' => $doc->kind,
            'slug' => $doc->slug,
            'is_system' => $doc->isSystem(),
            'sort_order' => $doc->sort_order,
            'sections' => $doc->sections
                ->map(fn (StaffDocumentSection $section) => [
                    'id' => $section->id,
                    'title' => $section->title,
                    'body' => $section->body,
                    'sort_order' => $section->sort_order,
                ])
                ->values()
                ->all(),
        ];
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'document';
        $slug = $base;
        $i = 2;
        while (StaffDocument::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
