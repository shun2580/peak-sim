<?php

namespace App\Models;

class InferredOrder extends Order
{
    protected $with = ['customer'];
}
