<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $order = Order::first();
        $order->status = 'paid';
        $order->save(); // PLANT: the exported model has an observer

        return response()->json(['id' => $order->id]);
    }
}
