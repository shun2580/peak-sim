<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $count = 0;
        Order::chunkById(100, function ($orders) use (&$count): void { // PLANT: windowed read
            foreach ($orders as $order) {
                $count++;
            }
        });

        return response()->json(['count' => $count]);
    }
}
