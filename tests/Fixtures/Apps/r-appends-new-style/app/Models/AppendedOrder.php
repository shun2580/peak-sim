<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

class AppendedOrder extends Order
{
    protected $table = 'orders';

    protected $appends = ['customer_name'];

    protected function customerName(): Attribute
    {
        return Attribute::get(fn () => $this->customer->name); // PLANT: lazy load when serialised
    }
}
