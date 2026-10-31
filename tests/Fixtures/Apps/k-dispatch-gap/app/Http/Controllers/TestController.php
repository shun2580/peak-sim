<?php

namespace App\Http\Controllers;

use App\Events\OrderViewed;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        event(new OrderViewed()); // PLANT: listeners are not followed

        return response()->json(Order::paginate(15));
    }
}
