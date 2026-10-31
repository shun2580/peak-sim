<?php

namespace App\Http\Controllers\Ok;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class BulkController extends Controller
{
    /** One pending order for every customer, written with a single insert(). */
    public function __invoke(): JsonResponse
    {
        $now = now();

        $rows = Customer::pluck('id')->map(fn (int $customerId) => [
            'customer_id' => $customerId,
            'status' => 'pending',
            'total' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        Order::insert($rows);

        return response()->json(['created' => count($rows)]);
    }
}
