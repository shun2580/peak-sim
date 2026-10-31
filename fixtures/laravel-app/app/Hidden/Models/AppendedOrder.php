<?php

namespace App\Hidden\Models;

use App\Models\Order;
use Illuminate\Database\Eloquent\Casts\Attribute;

/** Order whose serialized form always carries the customer name, read through the relation. */
class AppendedOrder extends Order
{
    protected $table = 'orders';

    protected $appends = ['customer_name'];

    protected function customerName(): Attribute
    {
        return Attribute::get(fn () => $this->customer->name);
    }
}
