<?php

namespace App\Events;

use App\Models\Laporan;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LaporanCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $laporan;
    public $nearbyVolunteersCount;

    /**
     * Create a new event instance.
     */
    public function __construct(Laporan $laporan, int $nearbyVolunteersCount = 0)
    {
        $this->laporan = $laporan->load(['pengguna']);
        $this->nearbyVolunteersCount = $nearbyVolunteersCount;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('laporan-channel'),
            new PrivateChannel('relawan-channel'),
        ];
    }

    /**
     * Nama event yang akan diterima oleh WebSocket client (Flutter / JS).
     */
    public function broadcastAs(): string
    {
        return 'LaporanCreated';
    }

    /**
     * Data payload real-time yang dikirimkan.
     */
    public function broadcastWith(): array
    {
        return [
            'id'                       => $this->laporan->id,
            'kategori_laporan'         => $this->laporan->kategori_laporan,
            'lokasi_laporan'           => $this->laporan->lokasi_laporan,
            'latitude'                 => $this->laporan->latitude,
            'longitude'                => $this->laporan->longitude,
            'deskripsi'                => $this->laporan->deskripsi,
            'waktu_laporan'            => $this->laporan->waktu_laporan,
            'pengguna_nama'            => $this->laporan->pengguna?->name,
            'nearby_volunteers_count'  => $this->nearbyVolunteersCount,
        ];
    }
}
