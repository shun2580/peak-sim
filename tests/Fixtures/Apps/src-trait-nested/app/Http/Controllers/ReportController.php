<?php

namespace App\Http\Controllers;

use App\Concerns\ReportsOrders;

class ReportController extends Controller
{
    use ReportsOrders;

    public function index()
    {
        return response()->json($this->report());
    }
}
