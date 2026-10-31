<?php

namespace App\Ng\Repositories;

use App\Models\Order;

class EloquentOrderRepository implements OrderRepository
{
    public function summaries(): array
    {
        $summaries = [];

        foreach (Order::all() as $order) {
            // Planted N+1: the customer is lazy-loaded for every order.
            $summaries[] = ['id' => $order->id, 'customer' => $order->customer->name];
        }

        return $summaries;
    }
}
