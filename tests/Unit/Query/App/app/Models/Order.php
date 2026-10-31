<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    public function tags()
    {
        return $this->belongsToMany(Product::class);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('created_at', '>', now()->subDay())->orderBy('id');
    }

    public function scopeWithBigItems(Builder $query): Builder
    {
        return $query->where('total', '>', 100)->with('items');
    }

    public function getCustomerNameAttribute()
    {
        return $this->customer->name;
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public static function newest(): ?self
    {
        return static::latest()->first();
    }
}
