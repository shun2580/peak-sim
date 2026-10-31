<?php

namespace App\Models;

/** Old-style accessor: getCustomerNameAttribute. */
class OldAppendedOrder extends Order
{
    protected $table = 'orders';

    protected $appends = ['customer_name'];

    public function getCustomerNameAttribute(): string
    {
        return $this->customer->name;
    }
}
