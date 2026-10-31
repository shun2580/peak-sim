<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        foreach (Customer::all() as $customer) {
            Order::firstOrCreate(['customer_id' => $customer->id], ['status' => 'new']); // PLANT: SELECT, then INSERT if missing
        }

        return response()->json(['done' => true]);
    }
}
