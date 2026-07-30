<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class VideoSurveillanceEvent extends Model
{
    protected $fillable = [
        'club_id',
        'code',
        'name',
        'description',
        'is_enabled',
        'trigger_key',
        'channel',
        'marker_title',
        'sort',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'sort' => 'integer',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public static function makeCode(string $name): string
    {
        $base = Str::slug($name, '_');
        if ($base === '') {
            $base = 'event';
        }
        $base = Str::limit($base, 48, '');

        return $base.'_'.Str::lower(Str::random(4));
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'club_id' => $this->club_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_enabled' => (bool) $this->is_enabled,
            'trigger_key' => $this->trigger_key,
            'channel' => $this->channel,
            'marker_title' => $this->marker_title,
            'sort' => (int) $this->sort,
        ];
    }
}
