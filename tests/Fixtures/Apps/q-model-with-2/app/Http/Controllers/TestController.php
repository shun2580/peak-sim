<?php

namespace App\Http\Controllers;

use App\Models\WithMoreOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(WithMoreOrder::get()); // PLANT: $with has two entries
    }
}
