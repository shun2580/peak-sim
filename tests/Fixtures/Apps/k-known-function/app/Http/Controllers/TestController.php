<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::limit(10)->get();

        return response()->json([
            'at' => now(),
            'rounded' => round(count($orders) / 2),
            'listed' => in_array('new', ['new', 'paid'], true),
        ]);
    }
}
