<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

/** New-style accessor: Attribute::get(fn) in a method named after the attribute. */
class AppendedOrder extends Order
{
    protected $table = 'orders';

    protected $appends = ['customer_name'];

    protected function customerName(): Attribute
    {
        return Attribute::get(fn () => $this->customer->name);
    }
}
