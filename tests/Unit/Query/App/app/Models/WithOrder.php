<?php

namespace App\Models;

class WithOrder extends Order
{
    protected $table = 'orders';

    protected $with = ['customer'];
}
