<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::get();
        $this->ping(3); // PLANT: ping -> pong -> ping

        return response()->json(count($orders));
    }

    private function ping(int $depth): int
    {
        return $depth <= 0 ? 0 : $this->pong($depth - 1);
    }

    private function pong(int $depth): int
    {
        return $depth <= 0 ? 0 : $this->ping($depth - 1);
    }
}
