<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $class = $request->query('driver');
        $driver = new $class(); // PLANT: the class name is a variable
        $driver->run();

        return response()->json(Order::paginate(15));
    }
}
