<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::get();
        $this->walk(3); // PLANT: direct recursion

        return response()->json(count($orders));
    }

    private function walk(int $depth): int
    {
        return $depth <= 0 ? 0 : $this->walk($depth - 1);
    }
}
