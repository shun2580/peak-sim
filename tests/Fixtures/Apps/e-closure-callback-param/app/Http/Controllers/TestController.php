<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $name = fn (Order $order) => $order->customer->name; // PLANT: the callback lives in a variable
        $names = Order::get()->map($name);

        return response()->json($names);
    }
}
