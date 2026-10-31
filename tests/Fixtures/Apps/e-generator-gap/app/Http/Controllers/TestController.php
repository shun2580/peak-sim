<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $count = 0;
        foreach ($this->rows() as $id) { // PLANT: a generator
            $count++;
        }

        return response()->json(['count' => $count]);
    }

    private function rows(): \Generator
    {
        foreach (Order::get() as $order) {
            yield $order->id;
        }
    }
}
