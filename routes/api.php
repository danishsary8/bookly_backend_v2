<?php

use App\Http\Controllers\Api\V1\CartReminderController;
use App\Http\Controllers\Api\V1\Catalog\AuthorController;
use App\Http\Controllers\Api\V1\Catalog\BookController;
use App\Http\Controllers\Api\V1\Catalog\CategoryController;
use App\Http\Controllers\Api\V1\Catalog\CollectionController;
use App\Http\Controllers\Api\V1\Catalog\PublisherController;
use App\Http\Controllers\Api\V1\Catalog\ReviewController as BookReviewController;
use App\Http\Controllers\Api\V1\Catalog\SeriesController;
use App\Http\Controllers\Api\V1\Customer\AddressController;
use App\Http\Controllers\Api\V1\Customer\AuthController as CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\CartController;
use App\Http\Controllers\Api\V1\Customer\CheckoutController;
use App\Http\Controllers\Api\V1\Customer\ConnectionController;
use App\Http\Controllers\Api\V1\Customer\CouponCheckController;
use App\Http\Controllers\Api\V1\Customer\DataExportController;
use App\Http\Controllers\Api\V1\Customer\EmailController;
use App\Http\Controllers\Api\V1\Customer\NewsletterCustomerController;
use App\Http\Controllers\Api\V1\Customer\OrderController;
use App\Http\Controllers\Api\V1\Customer\OrderPaymentController;
use App\Http\Controllers\Api\V1\Customer\PhoneCodeController;
use App\Http\Controllers\Api\V1\Customer\ProfileController;
use App\Http\Controllers\Api\V1\Customer\ReturnController;
use App\Http\Controllers\Api\V1\Customer\ReviewController as CustomerReviewController;
use App\Http\Controllers\Api\V1\Customer\SessionController as CustomerSessionController;
use App\Http\Controllers\Api\V1\Customer\SocialAuthController;
use App\Http\Controllers\Api\V1\Customer\StockAlertController;
use App\Http\Controllers\Api\V1\Customer\StoreCreditController;
use App\Http\Controllers\Api\V1\Customer\TelegramAuthController;
use App\Http\Controllers\Api\V1\Customer\WishlistController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\DemoTokenController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Newsletter\NewsletterController;
use App\Http\Controllers\Api\V1\Reader\EbookDeviceController;
use App\Http\Controllers\Api\V1\Reader\PreviewController as EbookPreviewController;
use App\Http\Controllers\Api\V1\Reader\ReaderController;
use App\Http\Controllers\Api\V1\Staff\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Staff\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Staff\Admin\ExchangeRateController;
use App\Http\Controllers\Api\V1\Staff\Admin\OrderExportController;
use App\Http\Controllers\Api\V1\Staff\Admin\ReportsController;
use App\Http\Controllers\Api\V1\Staff\Admin\StaffHealthController;
use App\Http\Controllers\Api\V1\Staff\Admin\StaffMemberController;
use App\Http\Controllers\Api\V1\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\Api\V1\Staff\Catalog\AuthorController as StaffAuthorController;
use App\Http\Controllers\Api\V1\Staff\Catalog\BookBulkController;
use App\Http\Controllers\Api\V1\Staff\Catalog\BookController as StaffBookController;
use App\Http\Controllers\Api\V1\Staff\Catalog\BookCsvController;
use App\Http\Controllers\Api\V1\Staff\Catalog\BookVariantController as StaffBookVariantController;
use App\Http\Controllers\Api\V1\Staff\Catalog\CategoryController as StaffCategoryController;
use App\Http\Controllers\Api\V1\Staff\Catalog\EbookFileController;
use App\Http\Controllers\Api\V1\Staff\Catalog\MediaUploadController;
use App\Http\Controllers\Api\V1\Staff\Catalog\PublisherController as StaffPublisherController;
use App\Http\Controllers\Api\V1\Staff\Catalog\SeriesController as StaffSeriesController;
use App\Http\Controllers\Api\V1\Staff\Catalog\StockController;
use App\Http\Controllers\Api\V1\Staff\Catalog\TrashController;
use App\Http\Controllers\Api\V1\Staff\CustomerController as StaffCustomerController;
use App\Http\Controllers\Api\V1\Staff\CustomerNoteController;
use App\Http\Controllers\Api\V1\Staff\CustomerTagController;
use App\Http\Controllers\Api\V1\Staff\NotificationController as StaffNotificationController;
use App\Http\Controllers\Api\V1\Staff\Orders\OrderBulkStatusController;
use App\Http\Controllers\Api\V1\Staff\Orders\OrderController as StaffOrderController;
use App\Http\Controllers\Api\V1\Staff\Orders\OrderDocumentController;
use App\Http\Controllers\Api\V1\Staff\Promotions\CollectionController as StaffCollectionController;
use App\Http\Controllers\Api\V1\Staff\Promotions\CouponController as StaffCouponController;
use App\Http\Controllers\Api\V1\Staff\Promotions\OfferController as StaffOfferController;
use App\Http\Controllers\Api\V1\Staff\Returns\ReturnController as StaffReturnController;
use App\Http\Controllers\Api\V1\Staff\Reviews\ReviewController as StaffReviewController;
use App\Http\Controllers\Api\V1\Staff\StaffNewsletterController;
use App\Http\Controllers\Api\V1\Staff\StaffSessionController;
use App\Http\Controllers\Api\V1\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok', 'version' => 'v1']));
Route::get('/health', HealthController::class);
// One-click sign-in for the docs page: a 2-hour token for the shared demo customer only (10 per hour per IP).
Route::post('/demo/token', DemoTokenController::class)->middleware('throttle:demo-token');

// The Bookly Telegram bot's updates (Telegram signs each one with the webhook secret).
Route::post('/telegram/webhook', TelegramWebhookController::class);

/*
| Public catalog (no login needed)
*/
Route::get('/books', [BookController::class, 'index'])->middleware('throttle:catalog');
Route::get('/catalog/recommendations', [BookController::class, 'recommendations'])->middleware('throttle:catalog');
Route::get('/books/{book}', [BookController::class, 'show'])->whereNumber('book');
Route::get('/books/{book}/also-bought', [BookController::class, 'alsoBought'])->whereNumber('book');
Route::get('/books/{book}/related', [BookController::class, 'related'])->whereNumber('book');
Route::get('/books/{book}/reviews', [BookReviewController::class, 'index'])->whereNumber('book');
Route::get('/authors', [AuthorController::class, 'index']);
Route::get('/authors/{author}', [AuthorController::class, 'show'])->whereNumber('author');
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/publishers', [PublisherController::class, 'index']);
// Seasonal collections (owner 2026-11-01): up to 2 live ones for the home page, and one by its web address.
Route::get('/collections/live', [CollectionController::class, 'live'])->middleware('throttle:catalog');
Route::get('/collections/{slug}', [CollectionController::class, 'show'])->where('slug', '[a-z0-9-]+')->middleware('throttle:catalog');
Route::get('/series', [SeriesController::class, 'index']);
Route::get('/series/{series}', [SeriesController::class, 'show'])->whereNumber('series');
Route::get('/delivery/options', [DeliveryController::class, 'options']);

/*
| Public newsletter (double opt-in, unsubscribe, RFC 8058 one-click)
*/
// Ebook "Look inside" preview: anyone, no account (ebooks design 2026-11-06).
Route::get('/reader/{book}/preview', [EbookPreviewController::class, 'show'])->whereNumber('book')->middleware('throttle:60,1');
Route::get('/reader/{book}/preview/pages/{page}', [EbookPreviewController::class, 'page'])->whereNumber(['book', 'page'])->middleware('throttle:60,1');

// Cart reminder emails: "stop these emails" link (owner 2026-11-05).
Route::post('/cart-reminders/stop', [CartReminderController::class, 'stop'])->middleware('throttle:20,1');
Route::post('/cart-reminders/stop/one-click', [CartReminderController::class, 'oneClick'])->middleware('throttle:20,1');

Route::prefix('newsletter')->group(function () {
    Route::post('/subscribe', [NewsletterController::class, 'subscribe'])->middleware('throttle:newsletter-subscribe');
    Route::post('/confirm', [NewsletterController::class, 'confirm']);
    Route::post('/unsubscribe', [NewsletterController::class, 'unsubscribe']);
    Route::post('/unsubscribe/one-click', [NewsletterController::class, 'oneClickUnsubscribe']);
});

/*
| Customer authentication
*/
Route::prefix('auth')->group(function () {
    Route::get('/options', [CustomerAuthController::class, 'options']);
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/register', [CustomerAuthController::class, 'register']);
        Route::post('/login', [CustomerAuthController::class, 'login']);
        Route::post('/reset-password', [CustomerAuthController::class, 'resetPassword']);
        Route::post('/social/{provider}', SocialAuthController::class)->whereIn('provider', ['google', 'facebook']);
    });
    Route::post('/forgot-password', [CustomerAuthController::class, 'forgotPassword'])->middleware('throttle:otp-send');
    Route::post('/telegram', [TelegramAuthController::class, 'login'])->middleware('throttle:telegram-link');
    Route::post('/telegram/status', [TelegramAuthController::class, 'status'])->middleware('throttle:telegram-status');
    Route::post('/phone/code', [PhoneCodeController::class, 'send'])->middleware('throttle:phone-code-send');
    Route::post('/phone/verify', [PhoneCodeController::class, 'verify'])->middleware('throttle:phone-code-verify');

    Route::middleware(['auth:sanctum', 'abilities:customer'])->group(function () {
        Route::post('/logout', [CustomerAuthController::class, 'logout']);
        Route::post('/verify-email', [CustomerAuthController::class, 'verifyEmail'])->middleware('throttle:auth');
        Route::post('/resend-verification', [CustomerAuthController::class, 'resendVerification'])->middleware('throttle:otp-send');
        Route::post('/telegram/phone', [TelegramAuthController::class, 'phone'])->middleware(['not.demo', 'throttle:telegram-link']);
        Route::post('/telegram/order-updates', [TelegramAuthController::class, 'orderUpdates'])->middleware(['not.demo', 'throttle:telegram-link']);
    });
});

/*
| Customer profile
*/
Route::middleware(['auth:sanctum', 'abilities:customer'])->prefix('me')->group(function () {
    Route::get('/', [ProfileController::class, 'show']);
    // "Download my data": one JSON file with everything the account shows (5 per hour).
    Route::get('/export', DataExportController::class)->middleware('throttle:5,60');
    Route::patch('/', [ProfileController::class, 'update']);
    Route::delete('/telegram', [ProfileController::class, 'disconnectTelegram'])->middleware('not.demo');
    Route::put('/password', [ProfileController::class, 'changePassword'])->middleware(['not.demo', 'throttle:auth']);
    Route::delete('/', [ProfileController::class, 'destroy'])->middleware(['not.demo', 'throttle:auth']);
    Route::post('/email', [EmailController::class, 'store'])->middleware(['not.demo', 'throttle:otp-send']);
    Route::post('/email/verify', [EmailController::class, 'verify'])->middleware(['not.demo', 'throttle:auth']);
    Route::post('/connections/{provider}', [ConnectionController::class, 'store'])->whereIn('provider', ['google', 'facebook'])->middleware(['not.demo', 'throttle:auth']);
    Route::delete('/connections/{provider}', [ConnectionController::class, 'destroy'])->whereIn('provider', ['google', 'facebook'])->middleware(['not.demo', 'throttle:auth']);
    Route::get('/store-credits', [StoreCreditController::class, 'index']);
    // "Where you're signed in": devices with a sign-in, and signing them out (not for the shared demo account).
    Route::get('/sessions', [CustomerSessionController::class, 'index']);
    Route::post('/sessions/sign-out-others', [CustomerSessionController::class, 'destroyOthers'])->middleware(['not.demo', 'throttle:auth']);
    Route::delete('/sessions/{id}', [CustomerSessionController::class, 'destroy'])->whereNumber('id')->middleware(['not.demo', 'throttle:auth']);
    Route::get('/stock-alerts', [StockAlertController::class, 'index']);
    Route::post('/stock-alerts', [StockAlertController::class, 'store']);
    Route::delete('/stock-alerts/{stockAlert}', [StockAlertController::class, 'destroy'])->whereNumber('stockAlert');
    Route::get('/newsletter', [NewsletterCustomerController::class, 'show']);
    Route::put('/newsletter', [NewsletterCustomerController::class, 'update']);
});

/*
| Customer shopping features: verified customers only.
*/
Route::middleware(['auth:sanctum', 'abilities:customer', 'verified.customer'])->group(function () {
    // Ebooks: library, reader pages (watermarked images, never the file), progress, devices.
    Route::get('/me/ebooks', [ReaderController::class, 'library']);
    Route::get('/me/ebook-devices', [EbookDeviceController::class, 'index']);
    Route::delete('/me/ebook-devices/{device}', [EbookDeviceController::class, 'destroy'])->whereNumber('device')->middleware('not.demo');
    Route::get('/reader/{book}', [ReaderController::class, 'show'])->whereNumber('book');
    Route::get('/reader/{book}/pages/{page}', [ReaderController::class, 'page'])->whereNumber(['book', 'page'])->middleware('throttle:120,1');
    Route::put('/reader/{book}/progress', [ReaderController::class, 'progress'])->whereNumber('book')->middleware('throttle:60,1');

    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::patch('/addresses/{address}', [AddressController::class, 'update'])->whereNumber('address');
    Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address');

    Route::get('/cart', [CartController::class, 'show']);
    Route::delete('/cart', [CartController::class, 'clear']);
    Route::post('/cart/items', [CartController::class, 'addItem']);
    Route::patch('/cart/items/{item}', [CartController::class, 'updateItem'])->whereNumber('item');
    Route::delete('/cart/items/{item}', [CartController::class, 'removeItem'])->whereNumber('item');
    // Coupon codes cannot be guessed: 10 tries a minute, and 5 wrong codes in a row lock checking for 15 minutes.
    Route::post('/cart/coupon/check', CouponCheckController::class)->middleware('throttle:coupon-check');

    Route::post('/checkout/preview', [CheckoutController::class, 'preview'])->middleware('throttle:30,1');
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:checkout');
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->whereNumber('order');
    Route::post('/orders/{order}/reorder', [OrderController::class, 'reorder'])->whereNumber('order')->middleware('throttle:10,1');
    // KHQR payment page (KHQR payments design 3.3): the page asks every 3 seconds.
    Route::get('/orders/{order}/payment', [OrderPaymentController::class, 'show'])->whereNumber('order')->middleware('throttle:khqr-poll');
    Route::post('/orders/{order}/payment/qr', [OrderPaymentController::class, 'qr'])->whereNumber('order')->middleware('throttle:khqr-action');
    Route::post('/orders/{order}/payment/simulate', [OrderPaymentController::class, 'simulate'])->whereNumber('order')->middleware('throttle:khqr-action');
    Route::get('/orders/{order}/invoice', [OrderController::class, 'invoice'])->whereNumber('order')->middleware('throttle:30,1');
    Route::get('/orders/{order}/returnable-items', [ReturnController::class, 'returnable'])->whereNumber('order');
    Route::post('/orders/{order}/returns', [ReturnController::class, 'store'])->whereNumber('order');
    Route::get('/returns', [ReturnController::class, 'index']);
    Route::get('/returns/{return}', [ReturnController::class, 'show'])->whereNumber('return');
    Route::delete('/returns/{return}', [ReturnController::class, 'destroy'])->whereNumber('return');

    Route::get('/reviews', [CustomerReviewController::class, 'index']);
    // The shared demo account can't write reviews: they would show on the public shop at once (owner 2026-11-03).
    Route::post('/books/{book}/reviews', [CustomerReviewController::class, 'store'])->whereNumber('book')->middleware(['not.demo', 'throttle:10,1']);
    Route::patch('/reviews/{review}', [CustomerReviewController::class, 'update'])->whereNumber('review')->middleware('not.demo');
    Route::delete('/reviews/{review}', [CustomerReviewController::class, 'destroy'])->whereNumber('review')->middleware('not.demo');

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::patch('/wishlist/{book}', [WishlistController::class, 'update'])->whereNumber('book');
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
    Route::get('/sessions', [StaffSessionController::class, 'index']);
    Route::delete('/sessions/{id}', [StaffSessionController::class, 'destroy'])->whereNumber('id');
    Route::post('/sessions/revoke-others', [StaffSessionController::class, 'revokeOthers']);
    Route::post('/sessions/revoke-all', [StaffSessionController::class, 'revokeAll']);
    Route::get('/login-history', [StaffSessionController::class, 'loginHistory']);

    Route::get('/notifications', [StaffNotificationController::class, 'index']);
    Route::post('/notifications/read-all', [StaffNotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [StaffNotificationController::class, 'markRead'])->whereUuid('notification');

    Route::get('/orders', [StaffOrderController::class, 'index'])->middleware('ability:orders:view,admin');
    // Invoices and packing slips: ?ids[]=1&ids[]=2 (up to 50 orders per print).
    Route::get('/orders/documents', OrderDocumentController::class)->middleware(['throttle:30,1', 'ability:orders:view,admin']);
    Route::get('/orders/{order}', [StaffOrderController::class, 'show'])->whereNumber('order')->middleware('ability:orders:view,admin');
    Route::post('/orders/{order}/status', [StaffOrderController::class, 'updateStatus'])->whereNumber('order')->middleware('ability:orders:manage,admin');
    Route::patch('/orders/{order}/staff-note', [StaffOrderController::class, 'updateStaffNote'])->whereNumber('order')->middleware(['throttle:30,1', 'ability:orders:manage,admin']);
    // One status for up to 50 orders (not cancel); each order changes on its own and failures are listed.
    Route::post('/orders/bulk-status', OrderBulkStatusController::class)->middleware(['throttle:20,1', 'ability:orders:manage,admin']);

    Route::get('/returns', [StaffReturnController::class, 'index'])->middleware('ability:orders:view,admin');
    Route::get('/returns/{return}', [StaffReturnController::class, 'show'])->whereNumber('return')->middleware('ability:orders:view,admin');
    Route::post('/returns/{return}/approve', [StaffReturnController::class, 'approve'])->whereNumber('return')->middleware('ability:orders:manage,admin');
    Route::post('/returns/{return}/reject', [StaffReturnController::class, 'reject'])->whereNumber('return')->middleware('ability:orders:manage,admin');
    Route::post('/returns/{return}/refund', [StaffReturnController::class, 'refund'])->whereNumber('return')->middleware('ability:orders:manage,admin');

    Route::get('/customers', [StaffCustomerController::class, 'index'])->middleware('ability:customers:view,admin');
    Route::get('/customers/{customer}', [StaffCustomerController::class, 'show'])->withTrashed()->middleware('ability:customers:view,admin');
    Route::get('/customers/{customer}/notes', [CustomerNoteController::class, 'index'])->whereNumber('customer')->middleware('ability:customers:view,admin');
    Route::post('/customers/{customer}/notes', [CustomerNoteController::class, 'store'])->whereNumber('customer')->middleware('ability:customers:manage,admin');
    Route::delete('/customer-notes/{note}', [CustomerNoteController::class, 'destroy'])->whereNumber('note')->middleware('ability:customers:manage,admin');
    Route::put('/customers/{customer}/tags', [CustomerTagController::class, 'update'])->whereNumber('customer')->middleware('ability:customers:manage,admin');
    Route::get('/customer-tags', [CustomerTagController::class, 'suggestions'])->middleware('ability:customers:view,admin');
    Route::post('/customers/{customer}/resend-verification', [StaffCustomerController::class, 'resendVerification'])->whereNumber('customer')->middleware('ability:customers:manage,admin');

    Route::get('/reviews', [StaffReviewController::class, 'index']);
    Route::post('/reviews/{review}/hide', [StaffReviewController::class, 'hide'])->whereNumber('review')->middleware('ability:reviews:manage,admin');
    Route::post('/reviews/{review}/show', [StaffReviewController::class, 'show'])->whereNumber('review')->middleware('ability:reviews:manage,admin');

    Route::get('/books', [StaffBookController::class, 'index'])->middleware('ability:catalog:view,admin');
    Route::get('/books/{book}', [StaffBookController::class, 'show'])->whereNumber('book')->middleware('ability:catalog:view,admin');
    Route::post('/books', [StaffBookController::class, 'store'])->middleware('ability:catalog:manage,admin');
    Route::patch('/books/{book}', [StaffBookController::class, 'update'])->middleware('ability:catalog:manage,admin');
    Route::post('/books/{book}/variants', [StaffBookVariantController::class, 'store'])->middleware('ability:catalog:manage,admin');
    Route::patch('/variants/{variant}', [StaffBookVariantController::class, 'update'])->middleware('ability:catalog:manage,admin');
    Route::post('/variants/{variant}/restock', [StockController::class, 'restock'])->whereNumber('variant')->middleware('ability:catalog:manage,admin');
    Route::get('/variants/{variant}/stock-history', [StockController::class, 'history'])->whereNumber('variant')->middleware('ability:catalog:view,admin');
    Route::get('/stock', [StockController::class, 'index'])->middleware('ability:catalog:view,admin');
    Route::post('/books/bulk', [BookBulkController::class, 'bulkBooks'])->middleware('ability:catalog:manage,admin');
    Route::post('/variants/bulk', [BookBulkController::class, 'bulkVariants'])->middleware('ability:catalog:manage,admin');
    Route::post('/authors', [StaffAuthorController::class, 'store'])->middleware('ability:catalog:manage,admin');
    Route::patch('/authors/{author}', [StaffAuthorController::class, 'update'])->middleware('ability:catalog:manage,admin');
    Route::post('/categories', [StaffCategoryController::class, 'store'])->middleware('ability:catalog:manage,admin');
    Route::patch('/categories/{category}', [StaffCategoryController::class, 'update'])->middleware('ability:catalog:manage,admin');
    Route::post('/publishers', [StaffPublisherController::class, 'store'])->middleware('ability:catalog:manage,admin');
    Route::patch('/publishers/{publisher}', [StaffPublisherController::class, 'update'])->middleware('ability:catalog:manage,admin');
    Route::post('/series', [StaffSeriesController::class, 'store'])->middleware('ability:catalog:manage,admin');
    Route::patch('/series/{series}', [StaffSeriesController::class, 'update'])->middleware('ability:catalog:manage,admin');
    Route::get('/coupons', [StaffCouponController::class, 'index'])->middleware('ability:catalog:view,admin');
    Route::get('/coupons/{coupon}', [StaffCouponController::class, 'show'])->middleware('ability:catalog:view,admin');
    // Menu badges (pending orders, open returns, low stock) for every staff member; numbers only.
    Route::get('/dashboard/counts', [DashboardController::class, 'counts']);
    Route::get('/collections', [StaffCollectionController::class, 'index'])->middleware('ability:catalog:view,admin');
    Route::get('/collections/{collection}', [StaffCollectionController::class, 'show'])->whereNumber('collection')->middleware('ability:catalog:view,admin');
    Route::get('/offers', [StaffOfferController::class, 'index'])->middleware('ability:catalog:view,admin');
    Route::get('/offers/{offer}', [StaffOfferController::class, 'show'])->whereNumber('offer')->middleware('ability:catalog:view,admin');
    Route::get('/books/{book}/ebook', [EbookFileController::class, 'show'])->middleware('ability:catalog:manage,admin');
    Route::post('/books/{book}/ebook', [EbookFileController::class, 'store'])->middleware(['throttle:uploads', 'ability:catalog:manage,admin']);
    Route::patch('/books/{book}/ebook', [EbookFileController::class, 'update'])->middleware('ability:catalog:manage,admin');
    Route::delete('/books/{book}/ebook', [EbookFileController::class, 'destroy'])->middleware('ability:catalog:manage,admin');
    Route::post('/customers/{customer}/ebook-devices/reset', [EbookFileController::class, 'resetDevices'])->whereNumber('customer')->middleware('ability:customers:manage,admin');
    Route::post('/media/upload', [MediaUploadController::class, 'upload'])->middleware(['throttle:uploads', 'ability:catalog:manage,admin']);

    Route::middleware('abilities:admin')->group(function () {
        Route::get('/books/export', [BookCsvController::class, 'export']);
        Route::get('/books/export-template', [BookCsvController::class, 'template']);
        Route::post('/books/import/preview', [BookCsvController::class, 'preview'])->middleware('throttle:book-import');
        Route::post('/books/import/confirm', [BookCsvController::class, 'confirm'])->middleware('throttle:book-import');
        Route::get('/members', [StaffMemberController::class, 'index']);
        Route::post('/members', [StaffMemberController::class, 'store']);
        Route::patch('/members/{member}', [StaffMemberController::class, 'update']);
        Route::get('/members/{member}/login-history', [StaffMemberController::class, 'loginHistory'])->whereNumber('member');
        Route::post('/members/{member}/deactivate', [StaffMemberController::class, 'deactivate']);
        Route::post('/members/{member}/activate', [StaffMemberController::class, 'activate']);
        Route::post('/members/{member}/reset-two-factor', [StaffMemberController::class, 'resetTwoFactor']);
        Route::post('/members/{member}/resend-invitation', [StaffMemberController::class, 'resendInvitation'])->middleware('throttle:otp-send');
        Route::post('/customers/{customer}/deactivate', [StaffCustomerController::class, 'deactivate']);
        Route::post('/customers/{customer}/activate', [StaffCustomerController::class, 'activate']);
        Route::delete('/customers/{customer}', [StaffCustomerController::class, 'destroy']);
        Route::post('/customers/{customer}/reopen', [StaffCustomerController::class, 'reopen'])->withTrashed();
        Route::post('/customers/{customer}/close', [StaffCustomerController::class, 'close'])->middleware('throttle:5,1');
        Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
        Route::post('/exchange-rates', [ExchangeRateController::class, 'store']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->middleware('throttle:admin-analytics');
        Route::get('/dashboard/sales', [DashboardController::class, 'sales'])->middleware('throttle:admin-analytics');
        Route::get('/dashboard/insights', [DashboardController::class, 'insights'])->middleware('throttle:admin-analytics');
        Route::get('/orders/export', OrderExportController::class)->middleware('throttle:10,1');

        Route::prefix('reports')->middleware('throttle:admin-analytics')->group(function () {
            Route::get('/sales', [ReportsController::class, 'sales']);
            Route::get('/orders', [ReportsController::class, 'orders']);
            Route::get('/products', [ReportsController::class, 'products']);
            Route::get('/customers', [ReportsController::class, 'customers']);
            Route::get('/funnel', [ReportsController::class, 'funnel']);
            Route::get('/coupons', [ReportsController::class, 'coupons']);
            Route::get('/offers', [ReportsController::class, 'offers']);
            Route::get('/export', [ReportsController::class, 'export'])->middleware('throttle:10,1');
        });

        Route::get('/trash', [TrashController::class, 'index']);
        Route::post('/trash/{type}/{id}/restore', [TrashController::class, 'restore'])->whereNumber('id');

        Route::delete('/books/{book}', [StaffBookController::class, 'destroy']);
        Route::post('/books/{book}/restore', [StaffBookController::class, 'restore'])->whereNumber('book');
        Route::delete('/variants/{variant}', [StaffBookVariantController::class, 'destroy']);
        Route::delete('/authors/{author}', [StaffAuthorController::class, 'destroy']);
        Route::delete('/categories/{category}', [StaffCategoryController::class, 'destroy']);
        Route::delete('/publishers/{publisher}', [StaffPublisherController::class, 'destroy']);
        Route::delete('/series/{series}', [StaffSeriesController::class, 'destroy']);
        Route::delete('/coupons/{coupon}', [StaffCouponController::class, 'destroy']);
        // Owner 2026-10-15 (security review): a coupon gives money away, so only admins make or change them.
        Route::post('/coupons', [StaffCouponController::class, 'store']);
        Route::patch('/coupons/{coupon}', [StaffCouponController::class, 'update']);
        // Owner 2026-10-31 (offers design): an offer gives money away too, so only admins make, change or delete them.
        // Owner 2026-11-01: seasonal collections are changed by admins only (like offers).
        Route::post('/collections', [StaffCollectionController::class, 'store']);
        Route::post('/orders/{order}/mark-paid', [StaffOrderController::class, 'markPaid'])->whereNumber('order')->middleware('throttle:30,1');
        Route::patch('/collections/{collection}', [StaffCollectionController::class, 'update'])->whereNumber('collection');
        Route::delete('/collections/{collection}', [StaffCollectionController::class, 'destroy'])->whereNumber('collection');
        Route::post('/offers', [StaffOfferController::class, 'store']);
        Route::patch('/offers/{offer}', [StaffOfferController::class, 'update'])->whereNumber('offer');
        Route::delete('/offers/{offer}', [StaffOfferController::class, 'destroy'])->whereNumber('offer');

        Route::get('/newsletter/summary', [StaffNewsletterController::class, 'summary']);
        Route::get('/newsletter/campaigns', [StaffNewsletterController::class, 'indexCampaigns']);
        Route::post('/newsletter/campaigns', [StaffNewsletterController::class, 'storeCampaign']);
        Route::get('/newsletter/campaigns/{campaign}', [StaffNewsletterController::class, 'showCampaign'])->whereNumber('campaign');
        Route::put('/newsletter/campaigns/{campaign}', [StaffNewsletterController::class, 'updateCampaign'])->whereNumber('campaign');
        Route::delete('/newsletter/campaigns/{campaign}', [StaffNewsletterController::class, 'destroyCampaign'])->whereNumber('campaign');
        Route::post('/newsletter/campaigns/{campaign}/test', [StaffNewsletterController::class, 'testSend'])->whereNumber('campaign');
        Route::post('/newsletter/campaigns/{campaign}/send', [StaffNewsletterController::class, 'send'])->whereNumber('campaign');

        Route::get('/health', [StaffHealthController::class, 'show']);
    });
});
