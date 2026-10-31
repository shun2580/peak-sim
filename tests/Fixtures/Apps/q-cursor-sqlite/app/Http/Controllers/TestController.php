<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $count = 0;
        foreach (Order::cursor() as $order) { // PLANT: cursor()
            $count++;
        }

        return response()->json(['count' => $count]);
    }
}
