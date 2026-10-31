<?php

namespace App\Models;

class TwoWithOrder extends Order
{
    protected $table = 'orders';

    protected $with = ['customer', 'items'];
}
