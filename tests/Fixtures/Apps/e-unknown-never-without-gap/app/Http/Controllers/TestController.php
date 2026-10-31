<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = undefined_helper(); // PLANT: no such function anywhere

        return response()->json(['count' => count($rows)]);
    }
}
