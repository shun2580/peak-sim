<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::with('items.product')->get(); // PLANT: two levels

        $names = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $names[] = $item->product->name;
            }
        }

        return response()->json($names);
    }
}
