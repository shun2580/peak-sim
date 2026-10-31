<?php

namespace App\Models;

class AppendedItem extends Item
{
    protected $table = 'items';

    protected $appends = ['product_name'];

    public function getProductNameAttribute(): string
    {
        return $this->product->name;
    }
}
