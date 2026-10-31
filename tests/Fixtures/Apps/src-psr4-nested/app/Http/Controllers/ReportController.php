<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Invoicer;

class ReportController extends Controller
{
    public function __construct(private readonly Invoicer $invoicer)
    {
    }

    public function index()
    {
        return response()->json($this->invoicer->totals());
    }
}
