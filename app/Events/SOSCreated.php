<?php

namespace App\Events;

use App\Models\SOS;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
//use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SOSCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */

    public $sos;
    public $targetRelawanId;
    public $targetRelawanIds;
    public $radius;

    /**
     * Create a new event instance.
     *
     * @param SOS $sos
     * @param int|array|\Illuminate\Support\Collection|null $targetRelawanIds
     * @param float|null $radius
     */
    public function __construct(SOS $sos, $targetRelawanIds = null, ?float $radius = null)
    {
        if ($sos->exists && !$sos->relationLoaded('pengguna')) {
            $sos->load('pengguna');
        }
        $this->sos = $sos;
        $this->radius = $radius;

        if ($targetRelawanIds instanceof \Illuminate\Support\Collection) {
            $this->targetRelawanIds = $targetRelawanIds->pluck('id')->map(fn($id) => (int)$id)->all();
        } elseif (is_array($targetRelawanIds)) {
            $this->targetRelawanIds = array_values(array_map('intval', $targetRelawanIds));
        } elseif (is_numeric($targetRelawanIds)) {
            $this->targetRelawanIds = [(int) $targetRelawanIds];
        } else {
            $this->targetRelawanIds = null;
        }

        // Backward compatibility untuk properti $targetRelawanId
        $this->targetRelawanId = (!empty($this->targetRelawanIds)) ? $this->targetRelawanIds[0] : null;
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
            new PrivateChannel('sos.' . $this->sos->id),
            new PrivateChannel('relawan-channel'),
        ];

        // Jika ditargetkan ke relawan tertentu (berdasarkan radius)
        if (!empty($this->targetRelawanIds)) {
            foreach ($this->targetRelawanIds as $relawanId) {
                $channels[] = new PrivateChannel('relawan.' . $relawanId);
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'SOSCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'id'          => $this->sos->id,
            'id_kasus'    => $this->sos->id_kasus,
            'nomor_kasus' => $this->sos->nomor_kasus,
            'id_pengguna' => $this->sos->id_pengguna,
            'id_relawan'  => $this->sos->id_relawan ?? null,
            'latitude'    => $this->sos->latitude,
            'longitude'   => $this->sos->longitude,
            'status_sos'  => $this->sos->status_sos,
            'radius'      => $this->radius,
            'waktu_sos'   => $this->sos->waktu_sos,
            'updated_at'  => $this->sos->updated_at,
            'pengguna'    => $this->sos->pengguna ? [
                'id'                => $this->sos->pengguna->id,
                'name'              => $this->sos->pengguna->name,
                'no_telp'           => $this->sos->pengguna->no_telp,
                'foto_profile'      => $this->sos->pengguna->foto_profile,
                'kategori_user'     => $this->sos->pengguna->kategori_user,
                'metode_komunikasi' => $this->sos->pengguna->metode_komunikasi,
            ] : null,
        ];
    }
}
