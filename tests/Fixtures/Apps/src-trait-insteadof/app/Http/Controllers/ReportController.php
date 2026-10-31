<?php

namespace App\Http\Controllers;

use App\Concerns\FastLoader;
use App\Concerns\SlowLoader;

class ReportController extends Controller
{
    use FastLoader, SlowLoader {
        FastLoader::load insteadof SlowLoader;
        SlowLoader::load as protected loadSlow;
    }

    public function index()
    {
        return response()->json([$this->load(), $this->loadSlow()]);
    }
}
