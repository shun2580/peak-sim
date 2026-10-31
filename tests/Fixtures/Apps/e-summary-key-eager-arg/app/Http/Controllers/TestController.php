<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderNamer;
use Illuminate\Http\JsonResponse;

class TestController extends Controller
{
    public function __construct(private readonly OrderNamer $namer)
    {
    }

    public function lazy(): JsonResponse
    {
        return response()->json($this->namer->names(Order::get()));
    }

    public function eager(): JsonResponse
    {
        return response()->json($this->namer->names(Order::with('customer')->get()));
    }

    public function both(): JsonResponse
    {
        $lazy = $this->namer->names(Order::get());
        $eager = $this->namer->names(Order::with('customer')->get());

        return response()->json([$lazy, $eager]);
    }
}
