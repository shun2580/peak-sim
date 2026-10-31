<?php

namespace App\Hidden\Contracts;

interface OrderSummaries
{
    /** @return list<array{id: int, status: string, total: string, customer: string}> */
    public function all(): array;
}
