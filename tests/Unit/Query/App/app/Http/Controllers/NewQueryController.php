<?php

namespace App\Http\Controllers;

use App\Models\Order;

class NewQueryController
{
    public function newQueryInLoop(): void
    {
        foreach (Order::all() as $order) {
            $order->newQuery()->where('status', 'paid')->count();
        }
    }

    public function newQueryThenGet(): void
    {
        $order = Order::first();
        $order->newQuery()->where('status', 'paid')->get();
    }

    public function unknownMethodOnTheInstance(): void
    {
        foreach (Order::all() as $order) {
            $order->mysteryMethod();
        }
    }

    public function unknownMethodOnTheNewQuery(): void
    {
        foreach (Order::all() as $order) {
            $order->newQuery()->mysteryMethod();
        }
    }

    public function newQueryOnAnUnknownReceiver(): void
    {
        foreach (Order::all() as $order) {
            $thing = \Vendor\Thing::make();
            $thing->newQuery()->count();
        }
    }
}
