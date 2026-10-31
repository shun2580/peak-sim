<?php

namespace App\Http\Controllers\Ng;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class BasicCollectionFilterController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $paid = Order::get()->where('status', 'paid');

        return response()->json($paid->values());
    }
}
