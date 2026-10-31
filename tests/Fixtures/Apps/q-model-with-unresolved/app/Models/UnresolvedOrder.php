<?php

namespace App\Models;

class UnresolvedOrder extends Order
{
    protected $table = 'orders';

    protected $with = [ORDER_RELATION]; // PLANT: a global constant defined with define(), not a literal
}
