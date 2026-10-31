<?php

use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/a-lazy', [TestController::class, 'lazy']);
Route::get('/b-eager', [TestController::class, 'eager']);
Route::get('/c-both', [TestController::class, 'both']);
