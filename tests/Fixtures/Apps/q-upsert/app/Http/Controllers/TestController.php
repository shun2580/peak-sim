<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Customer::pluck('id')
            ->map(fn (int $id) => ['id' => $id, 'status' => 'new'])
            ->all();
        Order::upsert($rows, ['id'], ['status']); // PLANT: one statement, N rows

        return response()->json(['upserted' => count($rows)]);
    }
}
