<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::with('customer:id,name')->get(); // PLANT: eager load with columns

        $names = [];
        foreach ($orders as $order) {
            $names[] = $order->customer->name;
        }

        return response()->json($names);
    }
}
