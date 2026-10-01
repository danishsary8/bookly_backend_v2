<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Interactive API reference (Swagger UI) for public/openapi.yaml
Route::view('/docs', 'docs')->name('docs');
