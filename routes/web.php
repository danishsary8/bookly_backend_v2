<?php

use App\Http\Controllers\Api\V1\SitemapController;
use Illuminate\Support\Facades\Route;

// The API has no home page: send visitors to the API reference.
Route::redirect('/', '/docs');

// Interactive API reference (Swagger UI) for public/openapi.yaml
Route::view('/docs', 'docs')->name('docs');

// Public SEO endpoints
Route::get('/sitemap.xml', [SitemapController::class, 'sitemap']);
Route::get('/robots.txt', [SitemapController::class, 'robots']);
