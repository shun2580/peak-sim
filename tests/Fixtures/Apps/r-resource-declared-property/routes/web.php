<?php

use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/plain-collection', [TestController::class, 'plainCollection']);
Route::get('/promoted-via', [TestController::class, 'promotedVia']);
Route::get('/unless-flag-plain', [TestController::class, 'unlessFlagPlain']);
Route::get('/accessor-direct', [TestController::class, 'accessorDirect']);
Route::get('/promoted-collection', [TestController::class, 'promotedCollection']);
Route::get('/prop', [TestController::class, 'prop']);
Route::get('/local-var', [TestController::class, 'localVar']);
Route::get('/unless-flag', [TestController::class, 'unlessFlag']);
Route::get('/accessor', [TestController::class, 'accessor']);
Route::get('/promoted-map-new', [TestController::class, 'promotedMapNew']);
Route::get('/promoted-make', [TestController::class, 'promotedMake']);
Route::get('/promoted-new', [TestController::class, 'promotedNew']);
