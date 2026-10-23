<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'bahitung-api',
    'health_url' => route('api.v1.health'),
]))->name('home');
