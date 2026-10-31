<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $ids = [];
        Order::chunk(100, function ($orders) use (&$ids): void {
            foreach ($orders as $order) {
                $ids[] = $order->id; // PLANT: keeps every id of every window
            }
        });

        return response()->json($ids);
    }
}
