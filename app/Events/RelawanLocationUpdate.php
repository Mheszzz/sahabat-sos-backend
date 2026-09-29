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
    public function __construct($sosId, float $latitude, float $longitude, ?float $heading, float $distance)
    {
        $this->sosId = $sosId;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->heading = $heading;
        $this->distance = $distance;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('sos.' . $this->sosId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'RelawanLocationUpdate';
    }
}
