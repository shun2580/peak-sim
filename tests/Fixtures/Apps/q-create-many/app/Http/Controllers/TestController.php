<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Customer::pluck('id')
            ->map(fn (int $id) => ['customer_id' => $id, 'status' => 'new'])
            ->all();
        Customer::first()->orders()->createMany($rows); // PLANT: one INSERT per row

        return response()->json(['created' => count($rows)]);
    }
}
