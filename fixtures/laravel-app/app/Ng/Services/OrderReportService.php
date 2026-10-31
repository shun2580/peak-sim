<?php

namespace App\Ng\Services;

use App\Models\Order;

class OrderReportService
{
    public function __construct(private readonly OrderRowBuilder $rows) {}

    /** @return list<array{id: int, customer: string, total: string}> */
    public function build(): array
    {
        $report = [];

        foreach (Order::all() as $order) {
            $report[] = $this->rows->build($order);
        }

        return $report;
    }
}
