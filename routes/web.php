<?php

use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Horizon;

Route::get('/', function () {
    return view('welcome');
});


Horizon::auth(function ($request) {
    return in_array($request->ip(), [
        '77.225.135.194',
    ]);
});
