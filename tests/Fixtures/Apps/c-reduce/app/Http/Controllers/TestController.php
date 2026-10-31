<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $total = Order::get()->reduce(fn ($carry, Order $order) => $carry + $order->customer->id, 0); // PLANT: callback runs once per order

        return response()->json(['total' => $total]);
    }
}
