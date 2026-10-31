<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Routing\Controller;

/** The parent lives in vendor/: anything not declared here may be inherited from it. */
class ReportController extends Controller
{
    public function index()
    {
        $names = [];
        foreach (Order::all() as $order) {
            $names[] = $order->customer->name;
        }

        return response()->json([$names, $this->inheritedFromVendor()]);
    }
}
