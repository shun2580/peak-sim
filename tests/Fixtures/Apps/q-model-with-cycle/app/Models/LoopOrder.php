<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoopOrder extends Model
{
    protected $table = 'orders';

    protected $with = ['loopCustomer']; // PLANT: Order -> Customer ...

    public function loopCustomer()
    {
        return $this->belongsTo(LoopCustomer::class, 'customer_id');
    }
}
