<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Route untuk Login Google
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/auth/google/mobile', [AuthController::class, 'loginGoogleMobile']);

Route::middleware('auth:sanctum')->group(function () {
    
    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);

    // Route Khusus Pengguna
    Route::middleware('role:pengguna')->group(function () {
        Route::get('/pengguna/profile', function (Request $request) {
            return response()->json(['user' => $request->user()]);
        });
    });

    // Route Khusus Relawan
    Route::middleware('role:relawan')->group(function () {
        Route::get('/relawan/profile', function (Request $request) {
            return response()->json(['user' => $request->user()]);
        });
    });
});