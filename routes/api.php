<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LaporanController;
use App\Http\Controllers\Api\ProfilePenggunaController;

// Public Authentication Routes
Route::post('/auth/register', [AuthController::class, 'register']);
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

    // Route Khusus Pengguna & Relawan (Beranda & CRUD Profile)
    Route::middleware('role:pengguna,relawan')->group(function () {
        Route::get('/beranda', [AuthController::class, 'beranda']);
        
        // CRUD Profil Pengguna (Nama, Foto, Kategori, Kontak, Aksesibilitas, Metode Komunikasi)
        Route::get('/pengguna/profile', [ProfilePenggunaController::class, 'show']);
        Route::put('/pengguna/profile', [ProfilePenggunaController::class, 'update']);
        Route::post('/pengguna/profile', [ProfilePenggunaController::class, 'update']); // Untuk Multipart Form-Data (Upload Foto)
        Route::post('/pengguna/profile/foto', [ProfilePenggunaController::class, 'uploadFoto']);
        Route::delete('/pengguna/profile/foto', [ProfilePenggunaController::class, 'destroyFoto']);
    });

    // Route Khusus Relawan
    Route::middleware('role:relawan')->group(function () {
        Route::get('/relawan/profile', function (Request $request) {
            return response()->json(['user' => $request->user(), 'is_profile_complete' => $request->user()->isProfileComplete()]);
        });
    });

    // Route Khusus Admin & Superadmin (Beranda Admin & Verifikasi Relawan)
    Route::middleware('role:admin,superadmin')->group(function () {
        Route::get('/admin/beranda', [AuthController::class, 'berandaAdmin']);
        Route::get('/admin/dashboard-stats', [AuthController::class, 'berandaAdmin']);
        
        // Verifikasi Relawan oleh Admin
        Route::get('/admin/relawan/pending', [AuthController::class, 'getPendingRelawan']);
        Route::put('/admin/relawan/{id}/verifikasi', [AuthController::class, 'verifikasiRelawan']);
    });
});