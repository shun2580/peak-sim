<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = Cache::remember('rows', 300, fn () => undefined_helper()); // PLANT: the closure cannot be followed

        return response()->json($rows);
    }
}
