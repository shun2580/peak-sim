<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::with('items')->get(); // PLANT: to-many eager load

        return response()->json($orders);
    }
}
