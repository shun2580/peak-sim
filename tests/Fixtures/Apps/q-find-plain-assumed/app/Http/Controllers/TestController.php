<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $order = Order::find($request->query('id')); // PLANT: one id, or an array of ids?

        return response()->json(['total' => $order->total]);
    }
}
