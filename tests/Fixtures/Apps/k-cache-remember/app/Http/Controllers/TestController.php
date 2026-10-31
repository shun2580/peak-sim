<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $names = Cache::remember('orders.names', 300, fn () => Order::get() // PLANT: cached N+1
            ->map(fn (Order $order) => $order->customer->name)
            ->all());

        return response()->json($names);
    }
}
