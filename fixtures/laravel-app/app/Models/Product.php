<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['name', 'price'];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function stat(): HasOne
    {
        return $this->hasOne(ProductStat::class);
    }
}
