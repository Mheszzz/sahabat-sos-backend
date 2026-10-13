<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class RelawanStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $relawanId;
    public $nama;
    public $isActive;
    public $statusKetersediaan;

    public function __construct(User $relawan)
    {
        $this->relawanId = $relawan->id;
        $this->nama = $relawan->name;
        $this->isActive = (bool) $relawan->is_active;
        $this->statusKetersediaan = $relawan->status_ketersediaan;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('command-center'),
            new Channel('relawan-locations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'RelawanStatusUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'relawan_id'          => $this->relawanId,
            'nama'                => $this->nama,
            'is_active'           => $this->isActive,
            'status_ketersediaan' => $this->statusKetersediaan,
        ];
    }
}
