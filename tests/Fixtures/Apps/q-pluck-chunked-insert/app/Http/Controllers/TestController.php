<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $ids = Customer::pluck('id')->all();
        foreach (array_chunk($ids, 1000) as $batch) { // PLANT: N/1000 inserts
            Order::insert(array_map(fn (int $id) => ['customer_id' => $id, 'status' => 'new'], $batch));
        }

        return response()->json(['created' => count($ids)]);
    }
}
