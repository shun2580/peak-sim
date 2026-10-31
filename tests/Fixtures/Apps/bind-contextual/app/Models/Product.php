<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    public function items()
    {
        return $this->hasMany(Item::class);
    }
}
