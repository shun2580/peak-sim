<?php

namespace App\Http\Controllers;

use App\Models\LoopOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(LoopOrder::get());
    }
}
