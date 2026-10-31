<?php

namespace App\Concerns;

use App\Models\Order;

trait SlowLoader
{
    public function load(): array
    {
        $names = [];
        foreach (Order::all() as $order) {
            $names[] = $order->customer->name;
        }

        return $names;
    }
}
