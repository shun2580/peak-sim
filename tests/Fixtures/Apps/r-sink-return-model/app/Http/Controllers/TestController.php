<?php

namespace App\Http\Controllers;

use App\Models\AppendedOrder;
use Illuminate\Database\Eloquent\Collection;

class TestController extends Controller
{
    public function index(): Collection
    {
        return AppendedOrder::get(); // PLANT: serialised by the framework
    }
}
