<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::get()->all();
        $names = array_map(fn (Order $order) => $order->customer->name, $orders); // PLANT: callback runs once per order

        return response()->json($names);
    }
}
