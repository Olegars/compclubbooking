<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('overlays')) {
            return;
        }

        $slots = [
            'top_left' => 'Верхний Левый',
            'top_right' => 'Верхний Правый',
            'mid_left' => 'Средний Левый',
            'mid_right' => 'Средний Правый',
            'bottom_left' => 'Нижний Левый',
            'bottom_right' => 'Нижний Правый',
        ];
        $now = now();
        $empty = json_encode(['layers' => [['type' => 'video', 'value' => '']]], JSON_UNESCAPED_UNICODE);

        foreach ($slots as $position => $title) {
            $exists = DB::table('overlays')->where('block_position', $position)->exists();
            if ($exists) {
                continue;
            }
            DB::table('overlays')->insert([
                'block_position' => $position,
                'title' => $title,
                'type' => 'video',
                'content' => $empty,
                'is_active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('overlays')) {
            return;
        }

        $positions = [
            'top_left', 'top_right', 'mid_left', 'mid_right', 'bottom_left', 'bottom_right',
        ];

        DB::table('overlays')
            ->whereIn('block_position', $positions)
            ->where('is_active', false)
            ->orderBy('id')
            ->get()
            ->each(function ($row) {
                $content = is_string($row->content) ? json_decode($row->content, true) : (array) $row->content;
                $layers = $content['layers'] ?? [];
                $onlyEmptyVideo = count($layers) === 1
                    && ($layers[0]['type'] ?? '') === 'video'
                    && trim((string) ($layers[0]['value'] ?? '')) === '';
                if ($onlyEmptyVideo) {
                    DB::table('overlays')->where('id', $row->id)->delete();
                }
            });
    }
};
