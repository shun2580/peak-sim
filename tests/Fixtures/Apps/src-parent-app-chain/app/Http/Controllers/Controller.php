<?php

namespace App\Http\Controllers;

use App\Models\Order;

abstract class Controller
{
    protected ?Order $latest = null;

    protected function customerNames(): array
    {
        $names = [];
        foreach (Order::all() as $order) {
            $names[] = $order->customer->name;
        }

        return $names;
    }

    protected function title(): string
    {
        return 'orders';
    }
}
