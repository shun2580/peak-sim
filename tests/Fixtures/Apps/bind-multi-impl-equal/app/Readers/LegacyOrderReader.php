<?php

namespace App\Readers;

use App\Contracts\OrderReader;
use App\Models\Order;

class LegacyOrderReader implements OrderReader
{
    public function names(): array
    {
        return Order::get() // PLANT: customer is read per order
            ->map(fn (Order $order) => $order->customer->name)
            ->all();
    }
}
