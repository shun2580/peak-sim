<?php

namespace App\Readers;

use App\Contracts\OrderReader;
use App\Models\Order;

class EagerOrderReader implements OrderReader
{
    public function names(): array
    {
        return Order::with('customer')->get()
            ->map(fn (Order $order) => $order->customer->name)
            ->all();
    }
}
