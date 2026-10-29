<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Events\SOSCreated;
use App\Models\SOS;
use App\Models\User;
use App\Models\SOSRejection;
use App\Models\SOSActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EscalateSOSJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $sosId;
    public $targetRadius;

    public function __construct($sosId, float $targetRadius = 3.0)
    {
        $this->sosId = $sosId;
        $this->targetRadius = $targetRadius;
    }

    public function handle(): void
    {
        $sos = SOS::find($this->sosId);

        // Jika SOS tidak ditemukan, atau status bukan 'belum ditangani',
        // atau sudah ada relawan yang mengambil tugas (id_relawan tidak null), hentikan eskalasi!
        if (!$sos || $sos->status_sos !== 'belum ditangani' || !is_null($sos->id_relawan)) {
            Log::info("Eskalasi SOS ID {$this->sosId} (radius {$this->targetRadius} km) dibatalkan karena SOS sudah ditangani, selesai, atau dibatalkan.");
            return;
        }

        // Jika targetRadius <= 0, ini adalah tahap akhir (broadcast umum ke seluruh relawan siaga)
        if ((float) $this->targetRadius <= 0.0) {
            try {
                broadcast(new SOSCreated($sos, null, null));
            } catch (\Throwable $e) {
                Log::warning("Gagal broadcast eskalasi akhir SOSCreated: " . $e->getMessage());
            }
            Log::info("Eskalasi tahap akhir SOS ID {$this->sosId}: Broadcast umum ke semua relawan siaga (relawan-channel).");

            SOSActivity::record(
                $sos->id,
                'sos_eskalasi',
                "Panggilan SOS dieskalasikan ke seluruh relawan siaga (broadcast umum)",
                null,
                ['radius' => 'seluruh relawan']
            );
            return;
        }

        $lat = (float) $sos->latitude;
        $lng = (float) $sos->longitude;

        // Cegah eksekusi job eskalasi duplikat untuk tier yang sama atau lebih rendah
        $tierCacheKey = "sos:{$this->sosId}:escalation_tier";
        $highestTierProcessed = (float) Cache::get($tierCacheKey, 0.0);
        if ($highestTierProcessed >= (float) $this->targetRadius && (float) $this->targetRadius > 0.0) {
            Log::info("Eskalasi SOS ID {$this->sosId} untuk radius {$this->targetRadius} km dilewati karena tier ini atau lebih tinggi ({$highestTierProcessed} km) sudah diproses sebelumnya.");
            return;
        }
        Cache::put($tierCacheKey, (float) $this->targetRadius, now()->addHours(2));

        // Ambil relawan yang telah menolak SOS ini agar tidak dikirimi notifikasi kembali
        $rejectedRelawanIds = SOSRejection::where('id_sos', $sos->id)->pluck('id_relawan')->toArray();

        // Cari semua relawan tersedia dalam target radius
        $volunteers = User::where('role', 'relawan')
            ->where('status_verifikasi', 'terverifikasi')
            ->where('is_active', true)
            ->where('status_ketersediaan', 'tersedia')
            ->nearby($lat, $lng, $this->targetRadius)
            ->whereNotIn('id', $rejectedRelawanIds)
            ->get();

        $volunteerIds = $volunteers->pluck('id')->toArray();

        Log::info("Eskalasi SOS ID {$this->sosId}: Ditemukan {$volunteers->count()} relawan tersedia dalam radius {$this->targetRadius} km.");

        // Broadcast sinyal SOS:
        // Selalu dipancarkan ke command-center Posko (dan ke relawan jika ada)
        try {
            broadcast(new SOSCreated($sos, $volunteerIds, $this->targetRadius));
            if ($volunteers->isNotEmpty()) {
                Log::info("Eskalasi SOS ID {$this->sosId} berhasil di-broadcast ke {$volunteers->count()} relawan tersedia (radius {$this->targetRadius} km) & Posko Command Center!");
            } else {
                Log::info("Eskalasi SOS ID {$this->sosId} (radius {$this->targetRadius} km): 0 relawan ditemukan. Sinyal eskalasi tetap dipancarkan ke Posko Command Center untuk intervensi manual.");
            }
        } catch (\Throwable $e) {
            Log::warning("Gagal broadcast eskalasi SOSCreated: " . $e->getMessage());
        }

        // Catat aktivitas eskalasi ke riwayat SOS
        SOSActivity::record(
            $sos->id,
            'sos_eskalasi',
            "Panggilan SOS dieskalasikan ke radius {$this->targetRadius} km ({$volunteers->count()} relawan tersedia terdeteksi)",
            null,
            [
                'radius'        => $this->targetRadius,
                'total_relawan' => $volunteers->count(),
                'relawan_ids'   => $volunteerIds,
            ]
        );

        // Alur eskalasi bertingkat dinamis (default: 3 km -> 5 km -> 10 km -> 0 km / broadcast umum)
        $delaySeconds = (int) config('sos.escalation_delay_seconds', 30);
        $escalationTiers = config('sos.escalation_tiers', [
            3.0 => 5.0,
            5.0 => 10.0,
            10.0 => 0.0,
        ]);

        $currentRadiusKey = (float) $this->targetRadius;
        foreach ($escalationTiers as $currentTier => $nextTier) {
            if (abs((float) $currentTier - $currentRadiusKey) < 0.001) {
                EscalateSOSJob::dispatch($sos->id, (float) $nextTier)->delay(now()->addSeconds($delaySeconds));
                $nextLabel = ((float) $nextTier <= 0.0) ? 'broadcast umum' : "radius {$nextTier} km";
                Log::info("SOS ID {$this->sosId}: Menjadwalkan eskalasi tahap berikutnya ({$nextLabel}) dalam {$delaySeconds} detik.");
                break;
            }
        }
    }
}
