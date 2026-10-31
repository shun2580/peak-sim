<?php

namespace App\Http\Controllers\Ng;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class BasicNestedController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $orders = Order::get();

        $rows = [];
        foreach ($orders as $order) {
            $products = [];
            foreach ($order->items as $item) {
                $products[] = $item->product->name;
            }
            $rows[] = ['id' => $order->id, 'products' => $products];
        }

        return response()->json($rows);
    }
}
