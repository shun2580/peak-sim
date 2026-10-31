<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemWithProduct extends Model
{
    protected $table = 'items';

    protected $with = ['product'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
