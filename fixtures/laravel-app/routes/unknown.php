<?php

// Routes for the /unknown group. Loaded by bootstrap/app.php with the "/unknown" prefix and the "web" middleware.
// Owner: the unknown group only (see FIXTURES.md).

use App\Http\Controllers\Unknown\DynamicController;
use Illuminate\Support\Facades\Route;

Route::get('dynamic', DynamicController::class);
