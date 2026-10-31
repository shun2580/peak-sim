<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Http\Controllers\OtherController;
use App\Http\Controllers\TestController;
use App\Readers\EagerOrderReader;
use App\Readers\LazyOrderReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(TestController::class)->needs(OrderReader::class)->give(LazyOrderReader::class); // PLANT
        $this->app->when(OtherController::class)->needs(OrderReader::class)->give(EagerOrderReader::class);
    }
}
