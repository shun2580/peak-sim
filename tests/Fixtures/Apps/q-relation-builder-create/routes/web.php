<?php

use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

Route::post('/t', [TestController::class, 'index']);
