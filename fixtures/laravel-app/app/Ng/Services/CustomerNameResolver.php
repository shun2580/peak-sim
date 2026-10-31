<?php

namespace App\Ng\Services;

use App\Models\Order;

class CustomerNameResolver
{
    public function nameOf(Order $order): string
    {
        // Lazy-loads the customer: one query per order.
        return $order->customer->name;
    }
}
