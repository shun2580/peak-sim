<?php

namespace App\Http\Controllers;

class OrderReportController extends ReportController
{
    public function index()
    {
        return response()->json([$this->title(), $this->subtitle(), $this->customerNames()]);
    }
}
