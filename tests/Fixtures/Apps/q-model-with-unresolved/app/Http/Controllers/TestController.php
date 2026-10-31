<?php

namespace App\Http\Controllers;

use App\Models\UnresolvedOrder;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(UnresolvedOrder::get());
    }
}
