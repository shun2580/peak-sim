<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['customer_id' => 'exists:customers,id']); // PLANT: an exists rule queries the database

        return response()->json(Order::paginate(15));
    }
}
