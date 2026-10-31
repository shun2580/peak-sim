<?php

namespace App\Http\Controllers\Hidden;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class CursorController extends Controller
{
    public function index(): JsonResponse
    {
        // Only a count and a sum are kept, so the response stays small: any memory growth comes from the driver.
        $count = 0;
        $total = 0.0;
        foreach (Order::cursor() as $order) {
            $count++;
            $total += (float) $order->total;
        }

        return response()->json(['count' => $count, 'total' => round($total, 2)]);
    }
}
