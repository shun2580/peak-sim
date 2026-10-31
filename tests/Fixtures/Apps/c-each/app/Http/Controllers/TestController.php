<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $total = 0;
        Order::get()->each(function (Order $order) use (&$total): void { // PLANT: callback runs once per order
            $total += $order->customer->id;
        });

        return response()->json(['total' => $total]);
    }
}
