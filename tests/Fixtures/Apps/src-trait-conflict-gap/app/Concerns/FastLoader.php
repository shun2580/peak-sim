<?php

namespace App\Concerns;

use App\Models\Order;

trait FastLoader
{
    public function load(): array
    {
        return Order::with('customer')->limit(10)->get()->all();
    }

    public function onlyFast(): int
    {
        return 1;
    }
}
