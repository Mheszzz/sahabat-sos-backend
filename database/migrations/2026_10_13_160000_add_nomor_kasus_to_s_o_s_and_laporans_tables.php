<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tambah kolom nomor_kasus ke tabel s_o_s jika belum ada
        Schema::table('s_o_s', function (Blueprint $table) {
            if (!Schema::hasColumn('s_o_s', 'nomor_kasus')) {
                $table->string('nomor_kasus', 50)->nullable()->after('id');
            }
        });

        // 2. Tambah kolom nomor_kasus ke tabel laporans jika belum ada
        Schema::table('laporans', function (Blueprint $table) {
            if (!Schema::hasColumn('laporans', 'nomor_kasus')) {
                $table->string('nomor_kasus', 50)->nullable()->after('id');
            }
        });

        // 3. Backfill data lama pada s_o_s: SOS-1, SOS-2, dst. berdasarkan ID
        $sosRecords = DB::table('s_o_s')->orderBy('id', 'asc')->get();
        foreach ($sosRecords as $index => $sos) {
            DB::table('s_o_s')
                ->where('id', $sos->id)
                ->update(['nomor_kasus' => 'SOS-' . ($sos->id)]);
        }

        // 4. Backfill data lama pada laporans: LP-1, LP-2, dst. berdasarkan ID
        $laporanRecords = DB::table('laporans')->orderBy('id', 'asc')->get();
        foreach ($laporanRecords as $index => $lap) {
            DB::table('laporans')
                ->where('id', $lap->id)
                ->update(['nomor_kasus' => 'LP-' . ($lap->id)]);
        }

        // 5. Tambahkan unique index setelah data terisi
        Schema::table('s_o_s', function (Blueprint $table) {
            $table->unique('nomor_kasus');
        });

        Schema::table('laporans', function (Blueprint $table) {
            $table->unique('nomor_kasus');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('s_o_s', function (Blueprint $table) {
            if (Schema::hasColumn('s_o_s', 'nomor_kasus')) {
                $table->dropUnique(['nomor_kasus']);
                $table->dropColumn('nomor_kasus');
            }
        });

        Schema::table('laporans', function (Blueprint $table) {
            if (Schema::hasColumn('laporans', 'nomor_kasus')) {
                $table->dropUnique(['nomor_kasus']);
                $table->dropColumn('nomor_kasus');
            }
        });
    }
};
