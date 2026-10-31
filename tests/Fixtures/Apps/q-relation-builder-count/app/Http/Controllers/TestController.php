<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $total = 0;
        foreach (Order::get() as $order) {
            $total += $order->items()->count(); // PLANT: a COUNT query per order
        }

        return response()->json(['total' => $total]);
    }
}
