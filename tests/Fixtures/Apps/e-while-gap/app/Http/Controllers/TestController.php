<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $i = 1;
        $sum = 0;
        while ($i <= 10) { // PLANT: while loops are not followed
            $sum += Order::find($i)->total;
            ++$i;
        }

        return response()->json(['sum' => $sum]);
    }
}
