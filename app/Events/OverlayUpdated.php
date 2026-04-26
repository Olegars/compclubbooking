<?php

namespace App\Events;

use App\Models\Overlay;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class OverlayUpdated implements ShouldBroadcastNow
{
    public $overlay;

    public function __construct(Overlay $overlay)
    {
        $this->overlay = $overlay;
    }

    public function broadcastOn()
    {
        // Канал, который будет слушать QML
        return new Channel('terminals.all');
    }

    public function broadcastAs()
    {
        return 'overlay.changed';
    }

    public function broadcastWith()
    {
        return [
            'block_position' => $this->overlay->block_position,
            'data' => $this->overlay->toArray()
        ];
    }
}
