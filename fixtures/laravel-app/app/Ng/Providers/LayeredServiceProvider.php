<?php

namespace App\Ng\Providers;

use App\Ng\Repositories\EloquentOrderRepository;
use App\Ng\Repositories\OrderRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the interfaces used by /ng/layered. Owner: see FIXTURES.md.
 */
class LayeredServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderRepository::class, EloquentOrderRepository::class);
    }
}
