<?php

namespace App\Services;

class OrderNamer
{
    public function names($orders): array
    {
        $names = [];
        foreach ($orders as $order) {
            $names[] = $order->customer->name; // PLANT: lazy unless $orders was loaded with customer
        }

        return $names;
    }
}
