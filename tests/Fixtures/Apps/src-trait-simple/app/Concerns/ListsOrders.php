<?php

namespace App\Concerns;

use App\Models\Order;

trait ListsOrders
{
    public function summarize(): array
    {
        $names = [];
        foreach (Order::all() as $order) {
            $names[] = $order->customer->name;
        }

        return $names;
    }
}
