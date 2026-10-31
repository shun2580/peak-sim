<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;

/** Attribute::make(get: ..., set: ...) with named arguments. */
class MadeAppendedOrder extends Order
{
    protected $table = 'orders';

    protected $appends = ['customer_name'];

    protected function customerName(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->customer->name,
            set: fn ($value) => $value,
        );
    }
}
