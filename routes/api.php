<?php

use App\Http\Controllers\Api\V1\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok', 'version' => 'v1']));

/*
| Customer authentication
*/
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [CustomerAuthController::class, 'register']);
        Route::post('/login', [CustomerAuthController::class, 'login']);
        Route::post('/reset-password', [CustomerAuthController::class, 'resetPassword']);
    });
    Route::post('/forgot-password', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:otp-send');

    Route::middleware(['auth:sanctum', 'abilities:customer'])->group(function () {
        Route::post('/logout', [CustomerAuthController::class, 'logout']);
        Route::post('/verify-email', [CustomerAuthController::class, 'verifyEmail'])->middleware('throttle:auth');
        Route::post('/resend-verification', [CustomerAuthController::class, 'resendVerification'])->middleware('throttle:otp-send');
    });
});

/*
| Customer profile
*/
Route::middleware(['auth:sanctum', 'abilities:customer'])->prefix('me')->group(function () {
    Route::get('/', [ProfileController::class, 'show']);
    Route::patch('/', [ProfileController::class, 'update']);
    Route::put('/password', [ProfileController::class, 'changePassword'])->middleware('throttle:auth');
});
