<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        $client = new \Vendor\Sdk\Client(); // PLANT: a class from nowhere
        $client->send('x');

        return response()->json(Order::paginate(15));
    }
}
