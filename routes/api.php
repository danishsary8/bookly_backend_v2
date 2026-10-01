<?php

use App\Http\Controllers\Api\V1\Customer\AddressController;
use App\Http\Controllers\Api\V1\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\CartController;
use App\Http\Controllers\Api\V1\Customer\ProfileController;
use App\Http\Controllers\Api\V1\Customer\SocialAuthController;
use App\Http\Controllers\Api\V1\Customer\WishlistController;
use App\Http\Controllers\Api\V1\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\Api\V1\Staff\Catalog\AuthorController as StaffAuthorController;
use App\Http\Controllers\Api\V1\Staff\Catalog\BookController as StaffBookController;
use App\Http\Controllers\Api\V1\Staff\Catalog\BookVariantController as StaffBookVariantController;
use App\Http\Controllers\Api\V1\Staff\Catalog\CategoryController as StaffCategoryController;
use App\Http\Controllers\Api\V1\Staff\Catalog\PublisherController as StaffPublisherController;
use App\Http\Controllers\Api\V1\Staff\Catalog\SeriesController as StaffSeriesController;
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
| Customer shopping features: verified customers only.
*/
Route::middleware(['auth:sanctum', 'abilities:customer', 'verified.customer'])->group(function () {
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::patch('/addresses/{address}', [AddressController::class, 'update'])->whereNumber('address');
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address');

    Route::get('/cart', [CartController::class, 'show']);
    Route::delete('/cart', [CartController::class, 'clear']);
    Route::post('/cart/items', [CartController::class, 'addItem']);
    Route::patch('/cart/items/{item}', [CartController::class, 'updateItem'])->whereNumber('item');
    Route::delete('/cart/items/{item}', [CartController::class, 'removeItem'])->whereNumber('item');

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{book}', [WishlistController::class, 'destroy'])->whereNumber('book');
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

/*
| Staff features: staff + admin can create/edit, deletes are admin-only.
*/
Route::prefix('staff')->middleware(['auth:sanctum', 'abilities:staff', 'staff.2fa'])->group(function () {
    Route::get('/books', [StaffBookController::class, 'index']);
    Route::get('/books/{book}', [StaffBookController::class, 'show'])->whereNumber('book');
    Route::post('/books', [StaffBookController::class, 'store']);
    Route::patch('/books/{book}', [StaffBookController::class, 'update']);
    Route::post('/books/{book}/variants', [StaffBookVariantController::class, 'store']);
    Route::patch('/variants/{variant}', [StaffBookVariantController::class, 'update']);
    Route::post('/authors', [StaffAuthorController::class, 'store']);
    Route::patch('/authors/{author}', [StaffAuthorController::class, 'update']);
    Route::post('/categories', [StaffCategoryController::class, 'store']);
    Route::patch('/categories/{category}', [StaffCategoryController::class, 'update']);
    Route::post('/publishers', [StaffPublisherController::class, 'store']);
    Route::patch('/publishers/{publisher}', [StaffPublisherController::class, 'update']);
    Route::post('/series', [StaffSeriesController::class, 'store']);
    Route::patch('/series/{series}', [StaffSeriesController::class, 'update']);

    Route::middleware('abilities:admin')->group(function () {
        Route::delete('/books/{book}', [StaffBookController::class, 'destroy']);
        Route::post('/books/{book}/restore', [StaffBookController::class, 'restore'])->whereNumber('book');
        Route::delete('/variants/{variant}', [StaffBookVariantController::class, 'destroy']);
        Route::delete('/authors/{author}', [StaffAuthorController::class, 'destroy']);
        Route::delete('/categories/{category}', [StaffCategoryController::class, 'destroy']);
        Route::delete('/publishers/{publisher}', [StaffPublisherController::class, 'destroy']);
        Route::delete('/series/{series}', [StaffSeriesController::class, 'destroy']);
    });
});
