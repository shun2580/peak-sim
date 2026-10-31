<?php

namespace App\Http\Controllers\Hidden;

use App\Hidden\Models\AppendedOrder;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AppendsController extends Controller
{
    public function index(): JsonResponse
    {
        // Serializing each model runs its $appends accessor, which reads the customer.
        return response()->json(AppendedOrder::get());
    }
}
