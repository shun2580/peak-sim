<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Readers\EagerOrderReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, EagerOrderReader::class);
    }
}
