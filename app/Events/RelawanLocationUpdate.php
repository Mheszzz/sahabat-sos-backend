<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RelawanLocationUpdate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */

    public $sosId;
    public $latitude;
    public $longitude;
    public $heading;
    public $distance;
    public $relawanId;
    public $nama;
    public $status;
    public $lokasiUser;

    public function __construct(
        $sosId,
        float $latitude,
        float $longitude,
        ?float $heading = null,
        float $distance = 0.0,
        $relawanId = null,
        $nama = null,
        $status = 'siaga_bebas',
        $lokasiUser = null
    ) {
        $this->sosId = $sosId;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->heading = $heading;
        $this->distance = $distance;
        $this->relawanId = $relawanId;
        $this->nama = $nama;
        $this->status = $status;
        $this->lokasiUser = $lokasiUser;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new Channel('command-center'),
            new Channel('relawan-locations'),
        ];

        if ($this->sosId) {
            $channels[] = new PrivateChannel('sos.' . $this->sosId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'RelawanLocationUpdate';
    }

    public function broadcastWith(): array
    {
        return [
            'sos_id'       => $this->sosId,
            'relawan_id'   => $this->relawanId,
            'nama'         => $this->nama,
            'latitude'     => $this->latitude,
            'longitude'    => $this->longitude,
            'heading'      => $this->heading,
            'distance'     => $this->distance,
            'status'       => $this->status,
            'lokasi_user'  => $this->lokasiUser,
            'updated_at'   => now()->toIso8601String(),
        ];
    }
}
