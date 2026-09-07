<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

// Public Authentication Routes
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

    // Route Khusus Pengguna
    Route::middleware('role:pengguna')->group(function () {
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

    // Route Khusus Admin & Superadmin
    Route::middleware('role:admin,superadmin')->group(function () {
        Route::get('/admin/dashboard-stats', function (Request $request) {
            return response()->json([
                'message' => 'Selamat datang di Admin Dashboard',
                'user'    => $request->user()
            ]);
        });
    });
});