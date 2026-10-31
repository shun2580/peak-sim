<?php

namespace App\Http\Controllers;

use App\Concerns\HasSubtitle;

class ReportController extends Controller
{
    use HasSubtitle;

    protected function title(): string
    {
        return 'report';
    }
}
