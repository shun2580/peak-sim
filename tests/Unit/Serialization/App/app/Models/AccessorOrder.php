<?php

namespace App\Models;

/** An accessor and no $appends: it runs only when the attribute is read. */
class AccessorOrder extends Order
{
    protected $table = 'orders';

    public function getCustomerNameAttribute(): string
    {
        return $this->customer->name;
    }
}
