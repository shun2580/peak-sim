<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class TestController extends Controller
{
    public function index(): JsonResponse
    {
        Storage::disk('s3')->put('report.txt', 'x'); // PLANT: a vendor call the tool does not model

        return response()->json(Order::paginate(15));
    }
}
