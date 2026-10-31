<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Readers\EloquentOrderReader;
use App\Readers\CachingOrderReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, EloquentOrderReader::class);
        $this->app->extend(OrderReader::class, fn (OrderReader $reader) => new CachingOrderReader($reader)); // PLANT: decorated
    }
}
