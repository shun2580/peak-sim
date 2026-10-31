<?php

namespace App\Readers;

use App\Contracts\OrderSource;
use App\Models\Order;

class EloquentOrderSource implements OrderSource
{
    public function names(): array
    {
        return Order::get() // PLANT: customer is read per order
            ->map(fn (Order $order) => $order->customer->name)
            ->all();
    }
}
