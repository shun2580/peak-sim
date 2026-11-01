<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    protected function owner(): Attribute
    {
        return Attribute::get(
            fn () => $this->customer->name, // PLANT: accessor that lazy-loads a relation
        );
    }
}
