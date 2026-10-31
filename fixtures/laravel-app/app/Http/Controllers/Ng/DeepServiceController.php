<?php

namespace App\Http\Controllers\Ng;

use App\Ng\Services\OrderReportService;
use Illuminate\Http\JsonResponse;

class DeepServiceController
{
    public function __construct(private readonly OrderReportService $report) {}

    /** Planted: the N+1 sits three service classes down (OrderReportService -> OrderRowBuilder -> CustomerNameResolver). */
    public function __invoke(): JsonResponse
    {
        return response()->json(['orders' => $this->report->build()]);
    }
}
