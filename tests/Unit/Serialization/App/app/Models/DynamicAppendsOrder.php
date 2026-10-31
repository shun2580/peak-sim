<?php

namespace App\Models;

class DynamicAppendsOrder extends Order
{
    protected $table = 'orders';

    protected $appends = [APPENDS_FROM_CONFIG];

    public function getCustomerNameAttribute(): string
    {
        return $this->customer->name;
    }
}
