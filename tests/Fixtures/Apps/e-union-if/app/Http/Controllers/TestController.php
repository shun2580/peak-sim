<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->query('all')) {
            $orders = Order::get();
        } else {
            $orders = Order::limit(10)->get();
        }

        $total = 0;
        foreach ($orders as $order) {
            $total += $order->customer->id; // PLANT: the loop runs over the worse branch
        }

        return response()->json(['total' => $total]);
    }
}
