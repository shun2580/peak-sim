<?php

namespace App\Providers;

use App\Contracts\OrderReader;
use App\Readers\EloquentOrderReader;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderReader::class, self::makeReader(...)); // PLANT: first-class callable
    }

    public static function makeReader(): OrderReader
    {
        return new EloquentOrderReader();
    }
}
