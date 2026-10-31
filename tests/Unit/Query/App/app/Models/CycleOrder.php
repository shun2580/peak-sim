<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CycleOrder extends Model
{
    protected $table = 'orders';

    protected $with = ['cycleCustomer'];

    public function cycleCustomer()
    {
        return $this->belongsTo(CycleCustomer::class);
    }
}
