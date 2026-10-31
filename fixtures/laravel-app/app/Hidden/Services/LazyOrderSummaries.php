<?php

namespace App\Hidden\Services;

use App\Hidden\Contracts\OrderSummaries;
use App\Models\Order;

class LazyOrderSummaries implements OrderSummaries
{
    public function all(): array
    {
        // The customer is loaded one order at a time.
        return Order::get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'status' => $order->status,
                'total' => $order->total,
                'customer' => $order->customer->name,
            ])
            ->all();
    }
}
