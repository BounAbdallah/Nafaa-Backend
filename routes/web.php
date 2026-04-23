<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app'     => 'NAFAA ERP API',
        'version' => '1.0.0',
        'status'  => 'operational',
    ]);
});
