<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\AppendedOrder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TestController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return OrderResource::collection(AppendedOrder::get());
    }
}
