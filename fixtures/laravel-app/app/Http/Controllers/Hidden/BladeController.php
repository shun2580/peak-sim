<?php

namespace App\Http\Controllers\Hidden;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Contracts\View\View;

class BladeController extends Controller
{
    public function index(): View
    {
        // The view reads $order->customer for every row (see resources/views/hidden/blade.blade.php).
        return view('hidden.blade', ['orders' => Order::get()]);
    }
}
