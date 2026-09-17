<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\SOS;

class SOSUpdateStatus implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */

    public $sos;

    public function __construct(SOS $sos)
    {
        $this->sos = $sos->load(['pengguna', 'relawan']);    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('relawan-channel'),
            new PrivateChannel('sos.' . $this->sos->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SOSUpdateStatus';
    }

    public function broadcastWith(): array
    {
        return [
            'id'          => $this->sos->id,
            'status_sos'  => $this->sos->status_sos,
            'id_pengguna' => $this->sos->id_pengguna,
            'id_relawan'  => $this->sos->id_relawan,
            'pelapor'     => [
                'id'      => $this->sos->pengguna?->id,
                'name'    => $this->sos->pengguna?->name,
                'no_telp' => $this->sos->pengguna?->no_telp,
            ],
            'relawan'     => $this->sos->relawan ? [
                'id'      => $this->sos->relawan->id,
                'name'    => $this->sos->relawan->name,
                'no_telp' => $this->sos->relawan->no_telp,
            ] : null,
            'updated_at'  => $this->sos->updated_at?->toIso8601String(),
        ];
    }
}
