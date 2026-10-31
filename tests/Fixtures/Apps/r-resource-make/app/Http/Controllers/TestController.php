<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Resources\Json\JsonResource;

class TestController extends Controller
{
    public function index(): JsonResource
    {
        return OrderResource::make(Order::first());
    }
}
