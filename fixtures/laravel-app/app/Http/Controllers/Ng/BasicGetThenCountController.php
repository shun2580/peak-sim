<?php

namespace App\Http\Controllers\Ng;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class BasicGetThenCountController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['count' => Order::get()->count()]);
    }
}
