<?php

namespace App\Http\Controllers\Hidden;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CacheController extends Controller
{
    public const KEY = 'hidden.orders';

    public function index(): JsonResponse
    {
        // On a miss this is an N+1; on a hit the whole list comes back from the cache without a query.
        $rows = Cache::remember(self::KEY, 300, fn () => Order::get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'status' => $order->status,
                'total' => $order->total,
                'customer' => $order->customer->name,
            ])
            ->all());

        return response()->json($rows);
    }
}
