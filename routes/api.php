<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LaporanController;

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
    
    // User profile & complete profile
    Route::get('/user/me', [AuthController::class, 'me']);
    Route::post('/user/complete-profile', [AuthController::class, 'completeProfile']);

    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);

    // Laporan Endpoints (Kirim Laporan Cepat, List, Detail, Update Status)
    Route::get('/laporan/options', [LaporanController::class, 'getOptions']);
    Route::get('/laporan', [LaporanController::class, 'index']);
    Route::post('/laporan', [LaporanController::class, 'store']);
    Route::get('/laporan/{id}', [LaporanController::class, 'show']);
    Route::put('/laporan/{id}/status', [LaporanController::class, 'updateStatus']);

    // Route Khusus Pengguna & Relawan (Beranda)
    Route::middleware('role:pengguna,relawan')->group(function () {
        Route::get('/beranda', [AuthController::class, 'beranda']);
        
        Route::get('/pengguna/profile', function (Request $request) {
            return response()->json(['user' => $request->user(), 'is_profile_complete' => $request->user()->isProfileComplete()]);
        });
    });

    // Route Khusus Relawan
    Route::middleware('role:relawan')->group(function () {
        Route::get('/relawan/profile', function (Request $request) {
            return response()->json(['user' => $request->user(), 'is_profile_complete' => $request->user()->isProfileComplete()]);
        });
    });

    // Route Khusus Admin & Superadmin (Beranda Admin)
    Route::middleware('role:admin,superadmin')->group(function () {
        Route::get('/admin/beranda', [AuthController::class, 'berandaAdmin']);
        Route::get('/admin/dashboard-stats', [AuthController::class, 'berandaAdmin']);
    });
});