<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $table = 'items';

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function withOrder()
    {
        return $this->belongsTo(WithOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
