<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::get()->all();
        $kept = array_filter($orders, fn (Order $order) => $order->customer->id > 1); // PLANT: callback runs once per order

        return response()->json(array_values($kept));
    }
}
