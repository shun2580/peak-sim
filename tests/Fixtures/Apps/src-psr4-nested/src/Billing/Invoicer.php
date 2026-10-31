<?php

namespace App\Domain\Billing;

use App\Models\Order;

class Invoicer
{
    public function totals(): array
    {
        $rows = [];
        foreach (Order::all() as $order) {
            $rows[] = $order->customer->name;
        }

        return $rows;
    }
}
