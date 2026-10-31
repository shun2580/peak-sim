<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $names = Order::get()->map(fn (Order $order) => $order->customer->name); // PLANT: callback runs once per order

        return response()->json($names);
    }
}
