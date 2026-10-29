<?php

namespace App\Events;

use App\Models\Laporan;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LaporanCreated implements ShouldBroadcastNow
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
        $channels = [
            new Channel('command-center'),
            new Channel('laporan-channel'),
            new PrivateChannel('relawan-channel'),
        ];

        if ($this->laporan->id_relawan) {
            $channels[] = new PrivateChannel('relawan.' . $this->laporan->id_relawan);
        }

        return $channels;
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
            'id_kasus'                 => $this->laporan->id_kasus,
            'nomor_kasus'              => $this->laporan->nomor_kasus,
            'status'                   => $this->laporan->status,
            'id_relawan'               => $this->laporan->id_relawan,
            'kategori_laporan'         => $this->laporan->kategori_laporan,
            'lokasi_laporan'           => $this->laporan->lokasi_laporan,
            'latitude'                 => $this->laporan->latitude,
            'longitude'                => $this->laporan->longitude,
            'deskripsi'                => $this->laporan->deskripsi,
            'waktu_laporan'            => $this->laporan->waktu_laporan,
            'foto_laporan_url'         => $this->laporan->foto_laporan_url,
            'rekam_suara_url'          => $this->laporan->rekam_suara_url,
            'pengguna_nama'            => $this->laporan->pengguna?->name,
            'nearby_volunteers_count'  => $this->nearbyVolunteersCount,
        ];
    }
}
