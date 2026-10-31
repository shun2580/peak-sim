<?php

namespace App\Models;

class WithMoreOrder extends Order
{
    protected $table = 'orders';

    protected $with = ['customer', 'items']; // PLANT
}
