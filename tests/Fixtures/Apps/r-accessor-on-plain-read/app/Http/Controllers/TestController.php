<?php

namespace App\Http\Controllers;

use App\Models\AccessorOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $names = [];
        foreach (AccessorOrder::get() as $order) {
            $names[] = $order->customer_name; // PLANT: attribute read
        }

        return response()->json($names);
    }
}
