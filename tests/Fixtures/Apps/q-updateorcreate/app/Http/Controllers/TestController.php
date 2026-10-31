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
            Order::updateOrCreate(['customer_id' => $customer->id], ['status' => 'paid']); // PLANT: SELECT, then UPDATE or INSERT
        }

        return response()->json(['done' => true]);
    }
}
