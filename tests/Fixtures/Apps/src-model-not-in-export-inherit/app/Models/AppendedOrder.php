<?php

namespace App\Models;

use App\Models\Concerns\HasShortCode;
use Illuminate\Database\Eloquent\Casts\Attribute;

class AppendedOrder extends EagerOrder
{
    use HasShortCode;

    /** Declared on top of EagerOrder's own $with: the catalog merges the chain. */
    protected $with = ['items'];

    protected $appends = ['customer_name', 'label'];

    public function getCustomerNameAttribute(): string
    {
        return $this->customer->name;
    }

    /** An accessor that is not appended: still a hidden call when the attribute is read. */
    public function getStatusLabelAttribute(): string
    {
        return 'open';
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'order');
    }
}
