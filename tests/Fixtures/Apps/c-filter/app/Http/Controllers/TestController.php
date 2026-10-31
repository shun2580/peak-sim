<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $kept = Order::get()->filter(fn (Order $order) => $order->customer->id > 1); // PLANT: callback runs once per order

        return response()->json($kept->values());
    }
}
