<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $total = 0;
        foreach ($request->query('ids', []) as $id) { // PLANT: the size comes from the caller
            $total += Order::find($id)->total;
        }

        return response()->json(['total' => $total]);
    }
}
