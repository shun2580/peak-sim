<?php

namespace App\Http\Controllers;

use App\Http\Resources\BuyerResource;
use App\Http\Resources\NestedResource;
use App\Http\Resources\OtherOrderResource;
use App\Http\Resources\PromotedResource;
use App\Http\Resources\PropResource;
use App\Models\Order;

class TestController extends Controller
{
    public function promotedEager()
    {
        return PromotedResource::collection(Order::with('customer')->get());
    }

    public function propEager()
    {
        return PropResource::collection(Order::with('customer')->get());
    }

    public function promotedEagerMapNew()
    {
        return Order::with('customer')->get()->map(fn (Order $o) => new PromotedResource($o));
    }

    public function promotedEagerMake()
    {
        return PromotedResource::make(Order::with('customer')->first());
    }

    public function nestedEager()
    {
        return NestedResource::collection(Order::with('items.product')->get());
    }

    public function promotedLazy()
    {
        return PromotedResource::collection(Order::get());
    }

    public function propLazy()
    {
        return PropResource::collection(Order::get());
    }

    public function promotedOtherRelation()
    {
        return PromotedResource::collection(Order::with('items')->get());
    }

    public function nestedShallow()
    {
        return NestedResource::collection(Order::with('items')->get());
    }

    public function otherOrder()
    {
        return Order::with('customer')->get()->map(fn (Order $o) => new OtherOrderResource($o, Order::find($o->id)));
    }

    public function buyer()
    {
        return Order::with('customer')->get()->map(fn (Order $o) => new BuyerResource($o, $o->customer));
    }
}
