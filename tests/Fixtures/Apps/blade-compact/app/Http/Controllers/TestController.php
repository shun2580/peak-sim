<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;

class TestController extends Controller
{
    public function index(): View
    {
        $orders = Order::get();

        return view('t', compact('orders')); // PLANT: compact()
    }
}
