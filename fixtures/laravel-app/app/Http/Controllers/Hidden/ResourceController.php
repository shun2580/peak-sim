<?php

namespace App\Http\Controllers\Hidden;

use App\Hidden\Resources\OrderResource;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ResourceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // OrderResource reads $this->customer for every row.
        return OrderResource::collection(Order::get());
    }
}
