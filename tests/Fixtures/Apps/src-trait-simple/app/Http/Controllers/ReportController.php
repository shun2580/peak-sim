<?php

namespace App\Http\Controllers;

use App\Concerns\ListsOrders;

class ReportController extends Controller
{
    use ListsOrders;

    public function index()
    {
        return response()->json($this->summarize());
    }
}
