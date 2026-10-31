<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $order = Order::create(['status' => 'new', 'total' => 0]); // PLANT: one INSERT

        return response()->json(['id' => $order->id]);
    }
}
