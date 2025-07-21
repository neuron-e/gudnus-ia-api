<?php

use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Horizon;

Route::get('/', function () {
    return view('welcome');
});


Horizon::auth(function ($request) {
    // ✅ AGREGAR MÁS IPs Y DEBUGGING
    $allowedIPs = [
        '77.225.135.194',  // Tu IP actual
        '127.0.0.1',       // Local
        '::1',             // IPv6 local
    ];

    $clientIP = $request->ip();
    \Log::info("Horizon access attempt from IP: {$clientIP}");

    return in_array($clientIP, $allowedIPs);
});
