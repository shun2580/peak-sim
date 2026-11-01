<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(static fn (): OrderReader => app(config('readers.orders'))); // PLANT: the class is chosen by a config value
    }
}
