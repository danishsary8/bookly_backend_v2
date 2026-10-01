<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok', 'version' => 'v1']));
