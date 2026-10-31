<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(DB::table('orders')->get()); // PLANT: query builder, no model
    }
}
