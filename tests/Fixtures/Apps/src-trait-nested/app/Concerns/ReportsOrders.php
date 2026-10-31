<?php

namespace App\Concerns;

trait ReportsOrders
{
    use LoadsCustomers;

    public function report(): array
    {
        return $this->loadCustomers();
    }
}
