<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $now = now();
        $rows = Customer::pluck('id')
            ->map(fn (int $id) => ['customer_id' => $id, 'status' => 'new', 'created_at' => $now]) // PLANT: N comes from customers
            ->all();
        Order::insert($rows);

        return response()->json(['created' => count($rows)]);
    }
}
