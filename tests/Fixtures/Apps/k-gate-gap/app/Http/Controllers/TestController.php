<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('list-orders'); // PLANT: the gate may call any policy

        return response()->json(Order::paginate(15));
    }
}
