<?php

namespace App\Http\Controllers;

use App\Http\Resources\ScalarChainResource;
use App\Http\Resources\UntypedPropResource;
use App\Http\Resources\VendorPropResource;
use App\Models\Order;

class TestController extends Controller
{
    public function untypedProp()
    {
        return UntypedPropResource::collection(Order::get());
    }

    public function vendorProp()
    {
        return Order::get()->map(fn (Order $o) => new VendorPropResource($o, new \Stripe\StripeClient('key')));
    }

    public function scalarChain()
    {
        return ScalarChainResource::collection(Order::get());
    }

    public function scalarChainPlain()
    {
        $order = Order::first();

        return $order->total->amount; // PLANT: a property read on a column value, in a controller
    }
}
