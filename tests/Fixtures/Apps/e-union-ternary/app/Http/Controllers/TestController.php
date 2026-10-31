<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = $request->query('all') ? Order::get() : Order::limit(10)->get(); // PLANT: either side

        $total = 0;
        foreach ($orders as $order) {
            $total += $order->customer->id;
        }

        return response()->json(['total' => $total]);
    }
}
