<?php

namespace App\Http\Controllers;

use App\Concerns\FastLoader;
use App\Concerns\SlowLoader;

/** Both traits define load() and nothing says which wins: a static reader cannot pick one. */
class ReportController extends Controller
{
    use FastLoader, SlowLoader;

    public function index()
    {
        return response()->json([$this->load(), $this->onlyFast()]);
    }
}
