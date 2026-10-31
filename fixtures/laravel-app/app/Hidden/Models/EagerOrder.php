<?php

namespace App\Hidden\Models;

use App\Models\Order;

/** Order that always loads its customer, declared on the model instead of at the call site. */
class EagerOrder extends Order
{
    protected $table = 'orders';

    protected $with = ['customer'];
}
