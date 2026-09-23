<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminManagementController;
use App\Http\Controllers\Api\LaporanController;
use App\Http\Controllers\Api\ProfilePenggunaController;
use App\Http\Controllers\Api\SOSController;
use App\Http\Controllers\Api\LaporanOptionManagementController;
use App\Http\Controllers\Api\KontakDaruratController;

// Public Authentication Routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/register/pengguna', [AuthController::class, 'registerPengguna']);
Route::post('/auth/register/relawan', [AuthController::class, 'registerRelawan']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/auth/google/mobile', [AuthController::class, 'loginGoogleMobile']);

// Login khusus Admin & Superadmin (Web React Dashboard)
Route::post('/auth/admin/login', [AuthController::class, 'adminLogin']);

// Authenticated Routes (Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    
    // User profile, location & complete profile
    Route::get('/user/me', [AuthController::class, 'me']);
    Route::post('/user/complete-profile', [AuthController::class, 'completeProfile']);
    Route::post('/user/update-location', [AuthController::class, 'updateLocation']);

    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);

    // Laporan Endpoints (Kirim Laporan Cepat, List, Nearby, Detail, Update Status)
    Route::get('/laporan/options', [LaporanController::class, 'getOptions']);
    Route::get('/laporan', [LaporanController::class, 'index']);
    Route::get('/laporan/nearby', [LaporanController::class, 'nearby']);
    Route::post('/laporan', [LaporanController::class, 'store']);
    Route::get('/laporan/{id}', [LaporanController::class, 'show']);
    Route::put('/laporan/{id}/status', [LaporanController::class, 'updateStatus']);

    // SOS ENdpoints
   


    // Route Khusus Pengguna & Relawan (Beranda & CRUD Profile)
    Route::middleware('role:pengguna,relawan')->group(function () {
        
        // CRUD Profil Pengguna (Nama, Foto, Kategori, Kontak, Aksesibilitas, Metode Komunikasi)
        Route::get('/pengguna/profile', [ProfilePenggunaController::class, 'show']);
        Route::put('/pengguna/profile', [ProfilePenggunaController::class, 'update']);
        Route::post('/pengguna/profile', [ProfilePenggunaController::class, 'update']); // Untuk Multipart Form-Data (Upload Foto)
        Route::post('/pengguna/profile/foto', [ProfilePenggunaController::class, 'uploadFoto']);
        Route::delete('/pengguna/profile/foto', [ProfilePenggunaController::class, 'destroyFoto']);
    });Route::get('/beranda', [AuthController::class, 'beranda']);
    
    //Route khusus pengguna
    Route::middleware('role:pengguna')->group(function () {
        // SOS Endpoints
        Route::post('/sos/trigger', [SOSController::class, 'store']); // membuat SOS baru
        Route::get('/sos/active', [SOSController::class, 'getActiveUserSOS']); //SOS tampil untuk pengguna
        Route::get('/sos/user/history', [SOSController::class, 'getUserSOSHistory']);
        Route::post('/sos/{id}/cancel', [SOSController::class, 'cancel']); //membatalkan SOS
        Route::get('/sos/{id}', [SOSController::class, 'show']); 

        // Kontak Darurat Endpoints (CRUD)
        Route::get('/pengguna/kontak-darurat', [KontakDaruratController::class, 'index']);
        Route::post('/pengguna/kontak-darurat', [KontakDaruratController::class, 'store']);
        Route::get('/pengguna/kontak-darurat/{id}', [KontakDaruratController::class, 'show']);
        Route::put('/pengguna/kontak-darurat/{id}', [KontakDaruratController::class, 'update']);
        Route::delete('/pengguna/kontak-darurat/{id}', [KontakDaruratController::class, 'destroy']);
        Route::patch('/pengguna/kontak-darurat/{id}/toggle-notif', [KontakDaruratController::class, 'toggleNotif']);
    });

    // Route Khusus Relawan
    Route::middleware('role:relawan')->group(function () {
        Route::get('/relawan/profile', function (Request $request) {
            return response()->json(['user' => $request->user(), 'is_profile_complete' => $request->user()->isProfileComplete()]);
        });
        // SOS Endpoints
        Route::get('/sos/active/relawan', [SOSController::class, 'getActiveRelawanSOS']); // SOS tampil untuk semua relawan
        Route::get('/sos/relawan/tasks', [SOSController::class, 'activeTask']); // menampilkan SOS yang sedang ditangani 
        Route::patch('/sos/{id}/status', [SOSController::class, 'updateStatus']); //menguubah status SOS (proses/selesai)

    });

    // Route Khusus Admin & Superadmin (Beranda Admin, Verifikasi Relawan, & Kelola Opsi Laporan)
    Route::middleware('role:admin,superadmin')->group(function () {
        // Beranda & Stats selalu bisa diakses Admin (meskipun permissions lain kosong)
        Route::get('/admin/beranda', [AuthController::class, 'berandaAdmin']);
        Route::get('/admin/dashboard-stats', [AuthController::class, 'berandaAdmin']);
        
        // Kelola Master Data Kategori Laporan & Pesan Cepat (Hanya Admin & Superadmin)
        Route::get('/admin/kategori-laporan', [LaporanOptionManagementController::class, 'indexKategori']);
        Route::post('/admin/kategori-laporan', [LaporanOptionManagementController::class, 'storeKategori']);
        Route::put('/admin/kategori-laporan/{id}', [LaporanOptionManagementController::class, 'updateKategori']);
        Route::delete('/admin/kategori-laporan/{id}', [LaporanOptionManagementController::class, 'destroyKategori']);

        Route::get('/admin/pesan-cepat', [LaporanOptionManagementController::class, 'indexPesan']);
        Route::post('/admin/pesan-cepat', [LaporanOptionManagementController::class, 'storePesan']);
        Route::put('/admin/pesan-cepat/{id}', [LaporanOptionManagementController::class, 'updatePesan']);
        Route::delete('/admin/pesan-cepat/{id}', [LaporanOptionManagementController::class, 'destroyPesan']);

        // Verifikasi Relawan oleh Admin (Wajib memiliki izin verifikasi_relawan)
        Route::middleware('permission:verifikasi_relawan')->group(function () {
            Route::get('/admin/relawan/pending', [AuthController::class, 'getPendingRelawan']);
            Route::put('/admin/relawan/{id}/verifikasi', [AuthController::class, 'verifikasiRelawan']);
        });
    });

    // Route Khusus Superadmin (Kelola Admin & Manajemen Hak Akses)
    Route::middleware('role:superadmin')->prefix('superadmin')->group(function () {
        Route::get('/admins', [AdminManagementController::class, 'index']);
        Route::post('/admins', [AdminManagementController::class, 'store']);
        Route::put('/admins/{id}/permissions', [AdminManagementController::class, 'updatePermissions']);
        Route::delete('/admins/{id}/permissions', [AdminManagementController::class, 'revokePermissions']);
    });

});

// Route proxy untuk mengambil file dari storage (berguna agar lolos CORS saat development dengan artisan serve)
Route::get('/storage-file/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) {
        abort(404);
    }
    return response()->file($fullPath);
})->where('path', '.*');