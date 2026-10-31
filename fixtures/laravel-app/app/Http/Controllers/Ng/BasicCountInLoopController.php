<?php

namespace App\Http\Controllers\Ng;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class BasicCountInLoopController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $orders = Order::get();

        $rows = [];
        foreach ($orders as $order) {
            $rows[] = ['id' => $order->id, 'items' => $order->items()->count()];
        }

        return response()->json($rows);
    }
}
