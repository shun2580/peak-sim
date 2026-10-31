<?php

// /ng routes: the simple patterns (loops, unbounded reads, collection filters, ...).
// Required from routes/ng.php. Owner: see FIXTURES.md.

use App\Http\Controllers\Ng\BasicCollectionFilterController;
use App\Http\Controllers\Ng\BasicCountInLoopController;
use App\Http\Controllers\Ng\BasicGetThenCountController;
use App\Http\Controllers\Ng\BasicNestedController;
use App\Http\Controllers\Ng\BasicNPlusOneController;
use App\Http\Controllers\Ng\BasicUnboundedController;
use Illuminate\Support\Facades\Route;

Route::get('/n-plus-one', BasicNPlusOneController::class);
Route::get('/nested', BasicNestedController::class);
Route::get('/count-in-loop', BasicCountInLoopController::class);
Route::get('/unbounded', BasicUnboundedController::class);
Route::get('/get-then-count', BasicGetThenCountController::class);
Route::get('/collection-filter', BasicCollectionFilterController::class);
