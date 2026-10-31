<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Contracts\View\View;

class TestController extends Controller
{
    public function index(): View
    {
        return \Illuminate\Support\Facades\View::make('t', ['orders' => Order::get()]); // PLANT: View::make()
    }
}
