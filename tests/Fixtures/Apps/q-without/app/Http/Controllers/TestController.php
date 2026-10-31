<?php

namespace App\Http\Controllers;

use App\Models\WithOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $names = [];
        foreach (WithOrder::without('customer')->get() as $order) { // PLANT: undoes $with
            $names[] = $order->customer->name;
        }

        return response()->json($names);
    }
}
