<?php

namespace App\Http\Controllers\Ng;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class BasicNPlusOneController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $orders = Order::get();

        $rows = [];
        foreach ($orders as $order) {
            $rows[] = ['id' => $order->id, 'customer' => $order->customer->name];
        }

        return response()->json($rows);
    }
}
