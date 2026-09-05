<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StaffDocument;
use App\Support\StaffEmploymentRules;
use App\Support\StaffFireSafetyRules;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_page_lists_default_documents(): void
    {
        $this->actingAs($this->makeAdmin('supervisor'), 'admin')
            ->get('/admin/config/documents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ClubDocuments')
                ->has('documents', 2)
                ->where('documents.0.title', 'Условия работы администратора')
                ->where('documents.0.kind', 'employment')
                ->has('documents.0.sections', 10)
                ->where('documents.1.title', 'Техника пожарной безопасности')
                ->where('documents.1.kind', 'fire_safety')
                ->has('documents.1.sections', 8)
            );
    }

    public function test_salary_employment_uses_configured_section_titles(): void
    {
        $supervisor = $this->makeAdmin('supervisor');
        $document = $this->employmentDocument();
        $sections = $document->sections()->orderBy('sort_order')->get();
        $first = $sections->first();

        $this->actingAs($supervisor, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/config/documents')
            ->put("/admin/config/documents/{$document->id}", [
                'title' => 'Условия работы администратора',
                'kind' => 'employment',
                'sections' => $sections->map(function ($section) use ($first) {
                    return [
                        'id' => $section->id,
                        'title' => $section->id === $first->id ? 'Новая пунктуальность' : $section->title,
                        'body' => $section->body,
                    ];
                })->all(),
            ])
            ->assertRedirect(route('admin.config.documents'));

        $intern = $this->makeAdmin('intern');
        $intern->update(['employment_pending' => true]);

        $this->actingAs($intern, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('employment.rules_title', 'Условия работы администратора')
                ->where('employment.rules.0.title', 'Новая пунктуальность')
                ->has('employment.rules', 10)
            );
    }

    public function test_can_add_section_and_new_document(): void
    {
        $document = $this->employmentDocument();
        $sections = $document->sections()->orderBy('sort_order')->get()
            ->map(fn ($section) => [
                'id' => $section->id,
                'title' => $section->title,
                'body' => $section->body,
            ])
            ->all();
        $sections[] = [
            'title' => 'Новый раздел',
            'body' => 'Текст нового раздела для устройства.',
        ];

        $this->actingAs($this->makeAdmin('supervisor'), 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/config/documents')
            ->put("/admin/config/documents/{$document->id}", [
                'title' => $document->title,
                'kind' => 'employment',
                'sections' => $sections,
            ])
            ->assertRedirect(route('admin.config.documents'));

        $this->assertSame(11, count(StaffEmploymentRules::ids()));

        $this->from('/admin/config/documents')
            ->post('/admin/config/documents', [
                'title' => 'Кодекс формы',
                'kind' => 'employment',
                'sections' => [
                    ['title' => 'Форма', 'body' => 'На смене чёрный верх и бейдж.'],
                ],
            ])
            ->assertRedirect(route('admin.config.documents'));

        $this->assertDatabaseHas('staff_documents', [
            'title' => 'Кодекс формы',
            'kind' => 'employment',
            'is_system' => false,
        ]);
        $this->assertSame(12, count(StaffEmploymentRules::ids()));
    }

    public function test_cannot_delete_system_document(): void
    {
        $document = $this->employmentDocument();

        $this->actingAs($this->makeAdmin('supervisor'), 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/config/documents')
            ->delete("/admin/config/documents/{$document->id}")
            ->assertRedirect(route('admin.config.documents'))
            ->assertSessionHasErrors('message');

        $this->assertDatabaseHas('staff_documents', ['id' => $document->id]);
        $this->assertCount(8, StaffFireSafetyRules::all());
    }

    public function test_floor_admin_cannot_edit_documents(): void
    {
        $document = $this->employmentDocument();

        $this->actingAs($this->makeAdmin('admin'), 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->put("/admin/config/documents/{$document->id}", [
                'title' => 'Взлом',
                'kind' => 'employment',
                'sections' => [['title' => 'A', 'body' => 'B']],
            ])
            ->assertRedirect('/admin/salary');
    }

    private function employmentDocument(): StaffDocument
    {
        $this->actingAs($this->makeAdmin('supervisor'), 'admin')->get('/admin/config/documents');

        return StaffDocument::query()->where('slug', StaffDocument::SLUG_EMPLOYMENT)->firstOrFail();
    }

    private function makeAdmin(string $role): Admin
    {
        return Admin::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@slots.test',
            'password' => 'password',
            'role' => $role,
            'base_rate' => 2000,
            'pay_type' => 'shift',
            'employment_pending' => $role === 'intern',
        ]);
    }
}
