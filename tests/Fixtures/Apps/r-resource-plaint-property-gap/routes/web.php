<?php

use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/untyped-prop', [TestController::class, 'untypedProp']);
Route::get('/vendor-prop', [TestController::class, 'vendorProp']);
Route::get('/scalar-chain', [TestController::class, 'scalarChain']);
Route::get('/scalar-chain-plain', [TestController::class, 'scalarChainPlain']);
