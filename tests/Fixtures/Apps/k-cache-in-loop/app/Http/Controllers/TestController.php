<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $names = [];
        foreach (Order::get() as $order) {
            $names[] = Cache::remember('customer.name.'.$order->id, 300, fn () => $order->customer->name); // PLANT: cached per order
        }

        return response()->json($names);
    }
}
