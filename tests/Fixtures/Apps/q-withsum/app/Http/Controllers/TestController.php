<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::withSum('items', 'quantity')->get(); // PLANT: summed in the same query

        return response()->json($orders->map(fn (Order $order) => ['id' => $order->id, 'quantity' => $order->items_sum_quantity]));
    }
}
