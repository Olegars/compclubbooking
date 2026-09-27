<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffDocument extends Model
{
    public const KIND_EMPLOYMENT = 'employment';

    public const KIND_FIRE_SAFETY = 'fire_safety';

    public const KIND_REACTOR_AC = 'reactor_ac';

    public const SLUG_EMPLOYMENT = 'employment';

    public const SLUG_FIRE_SAFETY = 'fire_safety';

    public const SLUG_REACTOR_AC = 'reactor_ac';

    /** @var list<string> */
    public const KINDS = [
        self::KIND_EMPLOYMENT,
        self::KIND_FIRE_SAFETY,
        self::KIND_REACTOR_AC,
    ];

    protected $fillable = [
        'slug',
        'kind',
        'title',
        'sort_order',
        'is_system',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_system' => 'boolean',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(StaffDocumentSection::class, 'document_id')->orderBy('sort_order')->orderBy('id');
    }

    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }
}
