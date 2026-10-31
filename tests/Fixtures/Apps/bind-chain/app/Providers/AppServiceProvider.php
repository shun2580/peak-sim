<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Contracts\OrderSource;
use App\Readers\EloquentOrderSource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, OrderSource::class); // PLANT: an interface bound to another interface
        $this->app->bind(OrderSource::class, EloquentOrderSource::class);
    }
}
