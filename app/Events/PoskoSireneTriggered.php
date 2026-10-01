<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PoskoSireneTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $payload;

    /**
     * Create a new event instance.
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Get the channels the event should broadcast on.
     * Menggunakan channel publik/presence agar seluruh client dashboard operator posko & relawan langsung menerima.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('posko-channel'),
            new Channel('relawan-channel'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'PoskoSireneTriggered';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
