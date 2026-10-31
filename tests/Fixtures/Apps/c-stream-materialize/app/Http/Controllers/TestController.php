<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::lazy(100)->all(); // PLANT: lazy() streams, all() materialises every row

        return response()->json(count($orders));
    }
}
