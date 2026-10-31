<?php

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/report/{name}', [ReportController::class, 'index']);
