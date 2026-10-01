<?php

use App\Http\Controllers\Api\V1\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\ProfileController;
use App\Http\Controllers\Api\V1\Customer\SocialAuthController;
use App\Http\Controllers\Api\V1\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\Api\V1\Catalog\AuthorController;
use App\Http\Controllers\Api\V1\Catalog\BookController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\PublisherController;
use App\Http\Controllers\Api\V1\Catalog\SeriesController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok', 'version' => 'v1']));

/*
| Public catalog (no login needed)
*/
Route::get('/books', [BookController::class, 'index']);
Route::get('/books/{book}', [BookController::class, 'show'])->whereNumber('book');
Route::get('/authors', [AuthorController::class, 'index']);
Route::get('/authors/{author}', [AuthorController::class, 'show'])->whereNumber('author');
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/publishers', [PublisherController::class, 'index']);
Route::get('/series', [SeriesController::class, 'index']);
Route::get('/series/{series}', [SeriesController::class, 'show'])->whereNumber('series');

/*
| Customer authentication
*/
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [CustomerAuthController::class, 'register']);
        Route::post('/login', [CustomerAuthController::class, 'login']);
        Route::post('/reset-password', [CustomerAuthController::class, 'resetPassword']);
        Route::post('/social/{provider}', SocialAuthController::class)->whereIn('provider', ['google', 'facebook']);
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

/*
| Staff authentication (admin + staff). Staff features require 2FA to be enabled.
*/
Route::prefix('staff/auth')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/login', [StaffAuthController::class, 'login']);
        Route::post('/two-factor/challenge', [StaffAuthController::class, 'challenge']);
        Route::post('/reset-password', [StaffAuthController::class, 'resetPassword']);
    });
    Route::post('/forgot-password', [StaffAuthController::class, 'forgotPassword'])->middleware('throttle:otp-send');

    Route::middleware(['auth:sanctum', 'abilities:staff'])->group(function () {
        Route::get('/me', [StaffAuthController::class, 'me']);
        Route::post('/logout', [StaffAuthController::class, 'logout']);
        Route::post('/two-factor/setup', [StaffAuthController::class, 'setupTwoFactor']);
        Route::post('/two-factor/confirm', [StaffAuthController::class, 'confirmTwoFactor'])->middleware('throttle:auth');
    });
});

// Staff feature routes (catalog management, orders, admin) are added here behind:
// ['auth:sanctum', 'abilities:staff', 'staff.2fa'] and, for admin-only, 'abilities:admin'.
