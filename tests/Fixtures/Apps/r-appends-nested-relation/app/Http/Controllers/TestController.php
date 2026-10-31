<?php

namespace App\Http\Controllers;

use App\Models\NestedOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(NestedOrder::with('customer')->get());
    }
}
