<?php

namespace App\Ng\UseCases;

use App\Ng\Domain\OrderSummaryService;

class ListOrderSummaries
{
    public function __construct(private readonly OrderSummaryService $summaries) {}

    /** @return list<array{id: int, customer: string}> */
    public function handle(): array
    {
        return $this->summaries->all();
    }
}
