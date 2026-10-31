<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        Order::get()->each->save(); // PLANT: higher-order proxy

        return response()->json(['done' => true]);
    }
}
