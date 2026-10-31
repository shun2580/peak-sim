<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $total = Cache::remember('orders.count', 60, fn () => Order::count()); // PLANT: cached scalar

        return response()->json(['total' => $total]);
    }
}
