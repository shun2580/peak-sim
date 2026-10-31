<?php

namespace App\Models;

class AppendedCustomer extends Customer
{
    protected $table = 'customers';

    protected $appends = ['orders_total'];

    public function getOrdersTotalAttribute(): int
    {
        return $this->orders->count(); // PLANT: lazy load per customer
    }
}
