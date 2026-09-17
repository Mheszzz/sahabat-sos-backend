<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use App\Events\SOSCreated;
use App\Models\SOS;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EscalateSOSJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $sosId;

    public function __construct($sosId)
    {
        $this->sosId = $sosId;
    }

    public function handle(): void
    {
        $sos = SOS::find($this->sosId);

        // Jika SOS masih 'aktif' (belum diambil/proses oleh relawan pertama)
        if ($sos && $sos->status_sos === 'aktif' && is_null($sos->id_relawan)) {
            $lat = (float) $sos->latitude;
            $lng = (float) $sos->longitude;

            // Cari semua relawan dalam radius 3 km
            $volunteers = User::where('role', 'relawan')
                ->nearby((float) $lat, (float) $lng, 3.0)
                ->get();

            Log::info("Eskalasi SOS ID {$this->sosId}: Ditemukan {$volunteers->count()} relawan dalam radius 3km.");

            if ($volunteers->isNotEmpty()) {
                // Broadcast sinyal SOS ke seluruh relawan di radius 3 km
                broadcast(new SOSCreated($sos))->toOthers();

                Log::info("Eskalasi SOS ID {$this->sosId} berhasil di-broadcast ke radius 3km!");
            }
        }
    }
}
