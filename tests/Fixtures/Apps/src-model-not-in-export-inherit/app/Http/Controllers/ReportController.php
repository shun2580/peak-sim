<?php

namespace App\Http\Controllers;

use App\Models\AppendedOrder;

class ReportController extends Controller
{
    public function index()
    {
        return AppendedOrder::all();
    }
}
