<?php

use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/promoted-eager', [TestController::class, 'promotedEager']);
Route::get('/prop-eager', [TestController::class, 'propEager']);
Route::get('/promoted-eager-map-new', [TestController::class, 'promotedEagerMapNew']);
Route::get('/promoted-eager-make', [TestController::class, 'promotedEagerMake']);
Route::get('/nested-eager', [TestController::class, 'nestedEager']);
Route::get('/promoted-lazy', [TestController::class, 'promotedLazy']);
Route::get('/prop-lazy', [TestController::class, 'propLazy']);
Route::get('/promoted-other-relation', [TestController::class, 'promotedOtherRelation']);
Route::get('/nested-shallow', [TestController::class, 'nestedShallow']);
Route::get('/other-order', [TestController::class, 'otherOrder']);
Route::get('/buyer', [TestController::class, 'buyer']);
