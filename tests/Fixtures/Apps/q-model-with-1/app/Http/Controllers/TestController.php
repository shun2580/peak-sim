<?php

namespace App\Http\Controllers;

use App\Models\WithOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(WithOrder::get());
    }
}
