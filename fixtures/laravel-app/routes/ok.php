<?php

// Routes for the /ok group. Loaded by bootstrap/app.php with the "/ok" prefix and the "web" middleware.
// Owner: the ok group only (see FIXTURES.md).

use App\Http\Controllers\Ok\BulkController;
use App\Http\Controllers\Ok\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('eager', [OrderController::class, 'eager']);
Route::get('paginate', [OrderController::class, 'paginate']);
Route::get('chunk', [OrderController::class, 'chunk']);
Route::get('lazy', [OrderController::class, 'lazy']);
Route::get('with-count', [OrderController::class, 'withCount']);
Route::get('bulk', BulkController::class);
