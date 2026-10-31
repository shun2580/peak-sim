<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CycleCustomer extends Model
{
    protected $table = 'customers';

    protected $with = ['cycleOrders'];

    public function cycleOrders()
    {
        return $this->hasMany(CycleOrder::class);
    }
}
