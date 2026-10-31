<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $ids = Order::get()->flatMap(fn (Order $order) => $order->items->pluck('id')); // PLANT: items read once per order

        return response()->json($ids);
    }
}
