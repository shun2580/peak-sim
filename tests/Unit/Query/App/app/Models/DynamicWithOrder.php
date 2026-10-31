<?php

namespace App\Models;

class DynamicWithOrder extends Order
{
    protected $table = 'orders';

    protected $with = [Elsewhere::RELATION];
}
