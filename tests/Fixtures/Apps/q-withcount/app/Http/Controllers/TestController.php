<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::withCount('items')->get(); // PLANT: counted in the same query

        return response()->json($orders->map(fn (Order $order) => ['id' => $order->id, 'items' => $order->items_count]));
    }
}
