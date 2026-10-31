<?php

namespace App\Models;

class NestedOrder extends Order
{
    protected $table = 'orders';

    public function customer()
    {
        return $this->belongsTo(AppendedCustomer::class, 'customer_id');
    }
}
