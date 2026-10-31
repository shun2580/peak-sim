<?php

// Routes for the /hidden group. Loaded by bootstrap/app.php with the "/hidden" prefix and the "web" middleware.
// Owner: the hidden group only (see FIXTURES.md). Each route hides its query outside the controller body.

use App\Http\Controllers\Hidden\AppendsController;
use App\Http\Controllers\Hidden\BladeController;
use App\Http\Controllers\Hidden\CacheController;
use App\Http\Controllers\Hidden\ConditionalBindingController;
use App\Http\Controllers\Hidden\CursorController;
use App\Http\Controllers\Hidden\ModelWithController;
use App\Http\Controllers\Hidden\ResourceController;
use Illuminate\Support\Facades\Route;

Route::get('blade', [BladeController::class, 'index']);
Route::get('resource', [ResourceController::class, 'index']);
Route::get('cache', [CacheController::class, 'index']);
Route::get('appends', [AppendsController::class, 'index']);
Route::get('model-with', [ModelWithController::class, 'index']);
Route::get('cursor', [CursorController::class, 'index']);
Route::get('conditional-binding', [ConditionalBindingController::class, 'index']);
