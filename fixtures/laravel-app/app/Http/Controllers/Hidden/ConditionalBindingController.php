<?php

namespace App\Http\Controllers\Hidden;

use App\Hidden\Contracts\OrderSummaries;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ConditionalBindingController extends Controller
{
    public function index(OrderSummaries $summaries): JsonResponse
    {
        return response()->json($summaries->all());
    }
}
