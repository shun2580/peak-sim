<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::get()->all();
        usort($orders, fn (Order $a, Order $b) => $a->customer->id <=> $b->customer->id); // PLANT: comparator reads a relation

        return response()->json($orders);
    }
}
