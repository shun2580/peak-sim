<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoopCustomer extends Model
{
    protected $table = 'customers';

    protected $with = ['loopOrders']; // PLANT: ... Customer -> Order

    public function loopOrders()
    {
        return $this->hasMany(LoopOrder::class, 'customer_id');
    }
}
