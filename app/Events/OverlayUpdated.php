<?php

namespace App\Events;

use App\Models\Overlay;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // Важно для мгновенной отправки
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OverlayUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Overlay $overlay
    ) {}

    // 1. Указываем канал (должен совпадать с тем, что в QML)
    public function broadcastOn(): array
    {
        return [
            new Channel('terminals.all'),
        ];
    }

    // 2. Указываем точное имя события (чтобы QML его узнал)
    public function broadcastAs(): string
    {
        return 'overlay.changed';
    }

    // 3. Указываем, какие данные полетят в сокет
    public function broadcastWith(): array
    {
        return [
            'block_position' => $this->overlay->block_position,
            'data' => [
                'title' => $this->overlay->title,
                'type' => $this->overlay->type,
                'content' => $this->overlay->content,
                'is_active' => $this->overlay->is_active,
            ]
        ];
    }
}
