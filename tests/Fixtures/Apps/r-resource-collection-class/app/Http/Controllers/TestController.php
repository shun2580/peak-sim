<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderCollection;
use App\Models\Order;

class TestController extends Controller
{
    public function index(): OrderCollection
    {
        return new OrderCollection(Order::get());
    }
}
