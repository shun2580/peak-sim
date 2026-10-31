<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $total = 0;
        foreach ([1, 2, 3] as $id) { // PLANT: a literal list is a bounded loop
            $total += Order::find($id)->total;
        }

        return response()->json(['total' => $total]);
    }
}
