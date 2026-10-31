<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $count = 0;
        foreach (Order::lazy(100) as $order) { // PLANT: streamed read
            $count++;
        }

        return response()->json(['count' => $count]);
    }
}
