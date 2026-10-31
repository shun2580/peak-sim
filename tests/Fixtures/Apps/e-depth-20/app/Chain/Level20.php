<?php

namespace App\Chain;

use App\Models\Order;

class Level20
{
    public function run(): array
    {
        $names = [];
        foreach (Order::get() as $order) {
            $names[] = $order->customer->name; // PLANT: the only query, at call depth 20
        }

        return $names;
    }
}
