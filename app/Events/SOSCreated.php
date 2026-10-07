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
            new PrivateChannel('sos.' . $this->sos->id),
        ];

        // Jika ditargetkan ke relawan tertentu (berdasarkan radius)
        if ($this->targetRelawanIds !== null) {
            foreach ($this->targetRelawanIds as $relawanId) {
                $channels[] = new PrivateChannel('relawan.' . $relawanId);
            }
        } else {
            // Jika tidak ada target spesifik (null), broadcast umum ke semua relawan
            $channels[] = new PrivateChannel('relawan-channel');
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
            'id_pengguna' => $this->sos->id_pengguna,
            'id_relawan'  => $this->sos->id_relawan ?? null,
            'latitude'    => $this->sos->latitude,
            'longitude'   => $this->sos->longitude,
            'status_sos'  => $this->sos->status_sos,
            'radius'      => $this->radius,
            'waktu_sos'   => $this->sos->waktu_sos,
            'updated_at'  => $this->sos->updated_at,
        ];
    }
}
