<?php

namespace App\Models;

class AccessorOrder extends Order
{
    protected $table = 'orders';

    public function getCustomerNameAttribute(): string
    {
        return $this->customer->name; // PLANT: runs on every read of ->customer_name
    }
}
