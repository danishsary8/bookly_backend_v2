<?php

use Illuminate\Support\Facades\Route;

// The API has no home page: send visitors to the API reference.
Route::redirect('/', '/docs');

// Interactive API reference (Swagger UI) for public/openapi.yaml
Route::view('/docs', 'docs')->name('docs');
