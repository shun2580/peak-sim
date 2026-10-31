<?php

namespace App\Hidden\Providers;

use App\Hidden\Contracts\OrderSummaries;
use App\Hidden\Services\EagerOrderSummaries;
use App\Hidden\Services\LazyOrderSummaries;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the implementation used by /hidden/conditional-binding: eager loading in production,
 * the N+1 implementation everywhere else. Which one runs depends on the environment.
 */
class ConditionalBindingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderSummaries::class, self::byEnvironment(...));
    }

    public static function byEnvironment(Application $app): OrderSummaries
    {
        return $app->environment('production')
            ? $app->make(EagerOrderSummaries::class)
            : $app->make(LazyOrderSummaries::class);
    }
}
