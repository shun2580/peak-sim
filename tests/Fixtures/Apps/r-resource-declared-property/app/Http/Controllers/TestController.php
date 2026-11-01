<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccessorDirectResource;
use App\Http\Resources\AccessorResource;
use App\Http\Resources\LocalVarResource;
use App\Http\Resources\PlainResource;
use App\Http\Resources\PromotedResource;
use App\Http\Resources\PromotedViaResource;
use App\Http\Resources\PropResource;
use App\Http\Resources\UnlessFlagPlainResource;
use App\Http\Resources\UnlessFlagResource;
use App\Models\Order;

class TestController extends Controller
{
    public function plainCollection()
    {
        return PlainResource::collection(Order::get());
    }

    public function promotedVia()
    {
        return PromotedViaResource::collection(Order::get());
    }

    public function unlessFlagPlain()
    {
        return UnlessFlagPlainResource::collection(Order::get());
    }

    public function accessorDirect()
    {
        return AccessorDirectResource::collection(Order::get());
    }

    public function promotedCollection()
    {
        return PromotedResource::collection(Order::get());
    }

    public function prop()
    {
        return PropResource::collection(Order::get());
    }

    public function localVar()
    {
        return LocalVarResource::collection(Order::get());
    }

    public function unlessFlag()
    {
        return UnlessFlagResource::collection(Order::get());
    }

    public function accessor()
    {
        return AccessorResource::collection(Order::get());
    }

    public function promotedMapNew()
    {
        return Order::get()->map(fn (Order $o) => new PromotedResource($o));
    }

    public function promotedMake()
    {
        return PromotedResource::make(Order::first());
    }

    public function promotedNew()
    {
        return new PromotedResource(Order::first());
    }
}
