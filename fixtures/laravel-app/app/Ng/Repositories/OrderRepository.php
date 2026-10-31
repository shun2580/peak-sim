<?php

namespace App\Ng\Repositories;

interface OrderRepository
{
    /** @return list<array{id: int, customer: string}> */
    public function summaries(): array;
}
