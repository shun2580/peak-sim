<?php

namespace App\Models;

/** Always loads its customer; not part of the artisan export, so its facts come from Order. */
class EagerOrder extends Order
{
    protected $table = 'orders';

    protected $with = ['customer'];
}
