<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        foreach (Order::get() as $order) {
            $order->items()->create(['quantity' => 1]); // PLANT: one INSERT per order
        }

        return response()->json(['done' => true]);
    }
}
