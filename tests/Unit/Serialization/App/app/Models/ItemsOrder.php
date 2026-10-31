<?php

namespace App\Models;

/** Its items are AppendedItem rows, so serialising a loaded `items` runs their accessor. */
class ItemsOrder extends Order
{
    protected $table = 'orders';

    public function items()
    {
        return $this->hasMany(AppendedItem::class, 'order_id');
    }
}
