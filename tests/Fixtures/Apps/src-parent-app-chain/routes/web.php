<?php

use App\Http\Controllers\OrderReportController;
use Illuminate\Support\Facades\Route;

Route::get('/report', [OrderReportController::class, 'index']);
