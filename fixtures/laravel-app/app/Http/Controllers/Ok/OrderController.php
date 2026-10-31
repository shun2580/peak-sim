<?php

namespace App\Http\Controllers\Ok;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

/** Order lists that are written the safe way: nothing here should be reported as a problem. */
class OrderController extends Controller
{
    /** Rows per query for chunk() and lazy(). The measurements use this constant. */
    public const CHUNK_SIZE = 100;

    public const PER_PAGE = 15;

    public function eager(): JsonResponse
    {
        $orders = Order::with('customer')->get();

        return response()->json($orders->map(fn (Order $order) => [
            'id' => $order->id,
            'customer' => $order->customer->name,
            'total' => $order->total,
        ]));
    }

    public function paginate(): JsonResponse
    {
        return response()->json(Order::paginate(self::PER_PAGE));
    }

    public function chunk(): JsonResponse
    {
        $count = 0;
        $total = 0.0;

        Order::chunk(self::CHUNK_SIZE, function ($orders) use (&$count, &$total): void {
            foreach ($orders as $order) {
                $count++;
                $total += (float) $order->total;
            }
        });

        return response()->json(['count' => $count, 'total' => round($total, 2)]);
    }

    public function lazy(): JsonResponse
    {
        $count = 0;
        $total = 0.0;

        foreach (Order::lazy(self::CHUNK_SIZE) as $order) {
            $count++;
            $total += (float) $order->total;
        }

        return response()->json(['count' => $count, 'total' => round($total, 2)]);
    }

    public function withCount(): JsonResponse
    {
        $orders = Order::withCount('items')->withSum('items', 'quantity')->get();

        return response()->json($orders->map(fn (Order $order) => [
            'id' => $order->id,
            'items_count' => $order->items_count,
            'items_sum_quantity' => (int) $order->items_sum_quantity,
        ]));
    }
}
