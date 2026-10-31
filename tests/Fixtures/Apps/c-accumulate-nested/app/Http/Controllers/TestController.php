<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $all = [];
        foreach (Order::get() as $order) {
            foreach ($order->items as $item) {
                $all[] = $item->id; // PLANT: keeps every item of every order
            }
        }

        return response()->json($all);
    }
}
