<?php

namespace App\Concerns;

use App\Models\Order;

trait LoadsCustomers
{
    public function loadCustomers(): array
    {
        $names = [];
        foreach (Order::all() as $order) {
            $names[] = $order->customer->name;
        }

        return $names;
    }
}
