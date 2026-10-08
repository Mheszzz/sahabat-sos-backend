<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;
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

        // Jika SOS tidak ditemukan, atau sudah bukan 'aktif' (sudah 'proses', 'selesai', atau 'batal'),
        // atau sudah ada relawan yang mengambil tugas (id_relawan tidak null), hentikan eskalasi!
        if (!$sos || $sos->status_sos !== 'aktif' || !is_null($sos->id_relawan)) {
            Log::info("Eskalasi SOS ID {$this->sosId} (radius {$this->targetRadius} km) dibatalkan karena SOS sudah diproses, selesai, atau dibatalkan.");
            return;
        }

        $lat = (float) $sos->latitude;
        $lng = (float) $sos->longitude;

        // Ambil relawan yang telah menolak SOS ini agar tidak dikirimi notifikasi kembali
        $rejectedRelawanIds = SOSRejection::where('id_sos', $sos->id)->pluck('id_relawan')->toArray();

        // Cari semua relawan dalam target radius
        $volunteers = User::where('role', 'relawan')
            ->nearby($lat, $lng, $this->targetRadius)
            ->whereNotIn('id', $rejectedRelawanIds)
            ->get();

        $volunteerIds = $volunteers->pluck('id')->toArray();

        Log::info("Eskalasi SOS ID {$this->sosId}: Ditemukan {$volunteers->count()} relawan dalam radius {$this->targetRadius} km.");

        if ($volunteers->isNotEmpty()) {
            // Broadcast sinyal SOS ke seluruh relawan di radius target
            broadcast(new SOSCreated($sos, $volunteerIds, $this->targetRadius))->toOthers();

            Log::info("Eskalasi SOS ID {$this->sosId} berhasil di-broadcast ke {$volunteers->count()} relawan (radius {$this->targetRadius} km)!");
        }

        // Catat aktivitas eskalasi ke riwayat SOS
        SOSActivity::record(
            $sos->id,
            'sos_eskalasi',
            "Panggilan SOS dieskalasikan ke radius {$this->targetRadius} km ({$volunteers->count()} relawan terdeteksi)",
            null,
            [
                'radius'        => $this->targetRadius,
                'total_relawan' => $volunteers->count(),
                'relawan_ids'   => $volunteerIds,
            ]
        );

        // Alur eskalasi bertingkat:
        // Jika saat ini di radius 3 km dan belum ada yang menerima, jadwalkan eskalasi berikutnya ke radius 5 km setelah 30 detik
        if ((float) $this->targetRadius === 3.0) {
            EscalateSOSJob::dispatch($sos->id, 5.0)->delay(now()->addSeconds(30));
            Log::info("SOS ID {$this->sosId}: Menjadwalkan eskalasi tahap berikutnya ke radius 5 km dalam 30 detik.");
        }
    }
}
