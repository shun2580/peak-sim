<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(DB::select('select * from orders')); // PLANT: raw SQL
    }
}
