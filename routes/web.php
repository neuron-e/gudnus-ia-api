<?php

use Illuminate\Support\Facades\Route;
use Laravel\Horizon\Horizon;

Route::get('/', function () {
    return view('welcome');
});


Horizon::auth(function ($request) {
    return in_array($request->ip(), [
        '47.61.40.68',
    ]);
});
