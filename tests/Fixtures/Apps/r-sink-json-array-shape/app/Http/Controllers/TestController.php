<?php

namespace App\Http\Controllers;

use App\Models\AppendedOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['orders' => AppendedOrder::get(), 'meta' => ['source' => 'db']]); // PLANT: models inside an array
    }
}
