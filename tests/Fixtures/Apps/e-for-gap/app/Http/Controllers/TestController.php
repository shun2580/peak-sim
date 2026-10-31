<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $sum = 0;
        for ($i = 1; $i <= 10; $i++) { // PLANT: for loops are not followed
            $sum += Order::find($i)->total;
        }

        return response()->json(['sum' => $sum]);
    }
}
