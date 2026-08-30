<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpaceLight extends Model
{
    public const LAYOUT_RGB = 'rgb';

    public const LAYOUT_DIMMER_RGB = 'dimmer_rgb';

    public const LAYOUT_RGBW = 'rgbw';

    public const EFFECT_NONE = 'none';

    public const EFFECT_RAINBOW = 'rainbow';

    public const COLORS = ['white', 'red', 'blue', 'green', 'yellow', 'purple'];

    protected $fillable = [
        'club_id',
        'space_id',
        'dmx_node_id',
        'start_channel',
        'fixture_count',
        'layout',
        'desired_color',
        'desired_brightness',
        'desired_effect',
        'applied_color',
        'applied_brightness',
        'applied_effect',
        'last_on_color',
        'last_on_brightness',
        'last_on_effect',
        'vacant',
        'last_error',
        'last_applied_at',
        'last_manual_at',
        'last_manual_by_computer_id',
        'last_applied_by_computer_id',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'space_id' => 'integer',
        'dmx_node_id' => 'integer',
        'start_channel' => 'integer',
        'fixture_count' => 'integer',
        'desired_brightness' => 'integer',
        'applied_brightness' => 'integer',
        'last_on_brightness' => 'integer',
        'vacant' => 'boolean',
        'last_applied_at' => 'datetime',
        'last_manual_at' => 'datetime',
        'last_manual_by_computer_id' => 'integer',
        'last_applied_by_computer_id' => 'integer',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function dmxNode(): BelongsTo
    {
        return $this->belongsTo(DmxNode::class);
    }

    public function lastManualByComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'last_manual_by_computer_id');
    }

    public function lastAppliedByComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'last_applied_by_computer_id');
    }

    public static function normalizeColor(string $color): string
    {
        $c = strtolower(trim($color));
        if ($c === self::EFFECT_RAINBOW) {
            return self::EFFECT_RAINBOW;
        }

        return in_array($c, self::COLORS, true) ? $c : 'white';
    }

    public static function normalizeEffect(string $effect, ?string $color = null): string
    {
        if (($color !== null && self::normalizeColor($color) === self::EFFECT_RAINBOW)
            || strtolower(trim($effect)) === self::EFFECT_RAINBOW) {
            return self::EFFECT_RAINBOW;
        }

        return self::EFFECT_NONE;
    }

    public static function normalizeBrightness(int $brightness): int
    {
        return max(0, min(100, $brightness));
    }

    public static function channelsPerFixture(string $layout): int
    {
        return match ($layout) {
            self::LAYOUT_DIMMER_RGB, self::LAYOUT_RGBW => 4,
            default => 3,
        };
    }

    public static function normalizeLayout(string $layout): string
    {
        return match ($layout) {
            self::LAYOUT_DIMMER_RGB => self::LAYOUT_DIMMER_RGB,
            self::LAYOUT_RGBW => self::LAYOUT_RGBW,
            default => self::LAYOUT_RGB,
        };
    }

    /** Inclusive last DMX channel used by this group. */
    public function endChannel(): int
    {
        $count = max(1, (int) $this->fixture_count);
        $width = self::channelsPerFixture(self::normalizeLayout((string) $this->layout));

        return (int) $this->start_channel + ($count * $width) - 1;
    }

    /**
     * @return array{0:int,1:int,2:int} RGB 0–255 at full scale (no brightness)
     */
    public static function rgbForColor(string $color): array
    {
        return match (self::normalizeColor($color)) {
            'red' => [255, 0, 0],
            'blue' => [0, 40, 255],
            'green' => [0, 255, 40],
            'yellow' => [255, 220, 0],
            'purple' => [180, 0, 255],
            default => [255, 255, 255],
        };
    }

    public function channelSpanOverlaps(int $start, int $end): bool
    {
        $mineStart = (int) $this->start_channel;
        $mineEnd = $this->endChannel();

        return $start <= $mineEnd && $end >= $mineStart;
    }
}
