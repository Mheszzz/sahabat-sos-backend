<?php

namespace App\Events;

use App\Models\SOS;
//use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
//use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SOSCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */

    public $sos;
    public $targetRelawanId;

    public function __construct(SOS $sos, $targetRelawanId = null)
    {
        $this->sos = $sos->load('pengguna');
        $this->targetRelawanId = $targetRelawanId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // Jika ditargetkan ke 1 relawan spesifik
        if ($this->targetRelawanId) {
            return [
                new PrivateChannel('relawan.' . $this->targetRelawanId),
                new PrivateChannel('sos.' . $this->sos->id),
            ];
        }

        // Broadcast umum ke semua relawan
        return [
            new PrivateChannel('relawan-channel'),
            new PrivateChannel('sos.' . $this->sos->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SOSCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->sos->id,
            'id_pengguna' => $this->sos->id_pengguna,
            'id_relawan' => $this->sos->id_relawan ?? null,
            'latitude' => $this->sos->latitude,
            'longitude' => $this->sos->longitude,
            'status_sos' => $this->sos->status_sos,
            'waktu_sos' => $this->sos->waktu_sos,
            'updated_at' => $this->sos->updated_at,
        ];
    }

    
}
