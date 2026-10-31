<?php

namespace App\Models;

/** $with is built from something a static reader cannot evaluate. */
class DynamicOrder extends Order
{
    protected $with = [SOME_GLOBAL_RELATION];
}
