<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OwnQueryOrder extends Model
{
    protected $table = 'orders';

    public function newQuery()
    {
        return parent::newQuery()->where('tenant_id', 1);
    }
}
