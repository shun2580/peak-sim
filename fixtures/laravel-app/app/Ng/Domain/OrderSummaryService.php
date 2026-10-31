<?php

namespace App\Ng\Domain;

use App\Ng\Repositories\OrderRepository;

class OrderSummaryService
{
    public function __construct(private readonly OrderRepository $orders) {}

    /** @return list<array{id: int, customer: string}> */
    public function all(): array
    {
        return $this->orders->summaries();
    }
}
