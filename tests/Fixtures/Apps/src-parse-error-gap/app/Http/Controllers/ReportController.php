<?php

namespace App\Http\Controllers;

use App\Services\Broken;
use App\Services\Fine;

class ReportController extends Controller
{
    public function index(Broken $broken, Fine $fine)
    {
        return response()->json([$broken->names(), $fine->name()]);
    }
}
