<?php

// /ng routes: the patterns hidden behind several layers (service, layered architecture).
// Required from routes/ng.php. Owner: see FIXTURES.md.

use App\Http\Controllers\Ng\DeepFirstOrCreateInLoopController;
use App\Http\Controllers\Ng\DeepLayeredController;
use App\Http\Controllers\Ng\DeepSaveInLoopController;
use App\Http\Controllers\Ng\DeepServiceController;
use Illuminate\Support\Facades\Route;

Route::get('/save-in-loop', DeepSaveInLoopController::class);
Route::get('/first-or-create-in-loop', DeepFirstOrCreateInLoopController::class);
Route::get('/service', DeepServiceController::class);
Route::get('/layered', DeepLayeredController::class);
