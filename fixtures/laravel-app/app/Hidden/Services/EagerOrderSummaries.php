<?php

namespace App\Hidden\Services;

use App\Hidden\Contracts\OrderSummaries;
use App\Models\Order;

class EagerOrderSummaries implements OrderSummaries
{
    public function all(): array
    {
        return Order::with('customer')->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'status' => $order->status,
                'total' => $order->total,
                'customer' => $order->customer->name,
            ])
            ->all();
    }
}
