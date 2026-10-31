<?php

namespace App\Models;

/** $appends names an attribute that no accessor in the app provides. */
class GhostAppendsOrder extends Order
{
    protected $table = 'orders';

    protected $appends = ['ghost_name'];
}
