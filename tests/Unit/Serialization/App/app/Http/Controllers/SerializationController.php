<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConditionalResource;
use App\Http\Resources\ForwardingResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\InvoiceCollection;
use App\Http\Resources\MysteryCollection;
use App\Http\Resources\OrderCollection;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderWithItemsResource;
use App\Http\Resources\ParentOrderResource;
use App\Http\Resources\WhenLoadedClosureResource;
use App\Http\Resources\WhenLoadedOrderResource;
use App\Http\Resources\WrappedCollection;
use App\Models\AccessorOrder;
use App\Models\AppendedOrder;
use App\Models\Category;
use App\Models\DynamicAppendsOrder;
use App\Models\GhostAppendsOrder;
use App\Models\ItemsOrder;
use App\Models\MadeAppendedOrder;
use App\Models\NestedOrder;
use App\Models\Order;
use App\Models\OldAppendedOrder;

/** One action per case; the unit tests walk each as a route action. */
class SerializationController
{
    // ---- resources ------------------------------------------------------------------------------------------

    public function resourceCollection()
    {
        return OrderResource::collection(Order::get());
    }

    public function resourceMake()
    {
        return OrderResource::make(Order::first());
    }

    public function resourceNew()
    {
        return new OrderResource(Order::first());
    }

    public function resourceWhenLoaded()
    {
        return WhenLoadedOrderResource::collection(Order::get());
    }

    public function resourceWhenLoadedEager()
    {
        return WhenLoadedOrderResource::collection(Order::with('customer')->get());
    }

    /** The loaded customer is an AppendedCustomer: returning it from whenLoaded still serialises it. */
    public function resourceWhenLoadedAppended()
    {
        return WhenLoadedOrderResource::collection(NestedOrder::with('customer')->get());
    }

    public function resourceWhenLoadedClosureNotLoaded()
    {
        return WhenLoadedClosureResource::collection(Order::get());
    }

    public function resourceWhenLoadedClosureLoaded()
    {
        return WhenLoadedClosureResource::collection(Order::with('customer')->get());
    }

    public function resourceConditional()
    {
        return ConditionalResource::collection(Order::get());
    }

    public function resourceNested()
    {
        return OrderWithItemsResource::collection(Order::get());
    }

    public function resourceCollectionClass()
    {
        return new OrderCollection(Order::get());
    }

    public function resourceCollectionNamed()
    {
        return new InvoiceCollection(Order::get());
    }

    public function resourceCollectionUnknown()
    {
        return new MysteryCollection(Order::get());
    }

    public function resourceCollectionWrapped()
    {
        return new WrappedCollection(Order::get());
    }

    public function resourceParentToArray()
    {
        return ParentOrderResource::collection(OldAppendedOrder::get());
    }

    public function resourceForwarding()
    {
        return ForwardingResource::collection(Order::get());
    }

    public function resourceResolve()
    {
        $data = OrderResource::collection(Order::get())->resolve();

        return response()->noContent();
    }

    public function resourceViaResponseJson()
    {
        return response()->json(OrderResource::collection(Order::get()));
    }

    public function resourceAdditional()
    {
        return OrderResource::collection(Order::get())->additional(['meta' => ['source' => 'db']]);
    }

    public function resourceCycle()
    {
        return CategoryResource::collection(Category::get());
    }

    public function resourceUnknownSize(array $ids)
    {
        return OrderResource::collection(Order::findMany($ids));
    }

    public function resourcePaginated()
    {
        return OrderResource::collection(Order::paginate(15));
    }

    public function resourceBuiltButNotReturned()
    {
        $resource = OrderResource::collection(Order::get());

        return response()->noContent();
    }

    // ---- $appends and accessors -----------------------------------------------------------------------------

    public function appendsNew()
    {
        return response()->json(AppendedOrder::get());
    }

    public function appendsMake()
    {
        return response()->json(MadeAppendedOrder::get());
    }

    public function appendsOld()
    {
        return response()->json(OldAppendedOrder::get());
    }

    public function appendsEagerNoLazy()
    {
        return response()->json(AppendedOrder::with('customer')->get());
    }

    public function appendsNestedRelation()
    {
        return response()->json(NestedOrder::with('customer')->get());
    }

    public function appendsRelationRows()
    {
        return response()->json(ItemsOrder::with('items')->get());
    }

    public function appendsRelationRowsNotLoaded()
    {
        return response()->json(ItemsOrder::get());
    }

    public function appendsSingle()
    {
        return response()->json(AppendedOrder::first());
    }

    public function appendsUnresolved()
    {
        return response()->json(DynamicAppendsOrder::get());
    }

    public function appendsGhost()
    {
        return response()->json(GhostAppendsOrder::get());
    }

    // ---- the sinks ------------------------------------------------------------------------------------------

    public function sinkReturnModels()
    {
        return AppendedOrder::get();
    }

    public function sinkReturnModel()
    {
        return AppendedOrder::first();
    }

    public function sinkJsonArrayShape()
    {
        return response()->json(['orders' => AppendedOrder::get(), 'meta' => ['source' => 'db']]);
    }

    public function sinkToArray()
    {
        $rows = AppendedOrder::get()->toArray();

        return response()->noContent();
    }

    public function sinkToJson()
    {
        $json = AppendedOrder::first()->toJson();

        return response()->noContent();
    }

    public function sinkJsonSerialize()
    {
        $data = AppendedOrder::first()->jsonSerialize();

        return response()->noContent();
    }

    public function sinkJsonEncode()
    {
        $json = json_encode(AppendedOrder::get());

        return response()->noContent();
    }

    public function sinkNothingSerialized()
    {
        $orders = AppendedOrder::get();

        return response()->noContent();
    }

    public function sinkPlainJson()
    {
        return response()->json(['ok' => true]);
    }

    public function sinkTwiceIsTwo()
    {
        $orders = AppendedOrder::get();
        $a = json_encode($orders);
        $b = json_encode($orders);

        return response()->noContent();
    }

    // ---- accessors read directly ----------------------------------------------------------------------------

    public function plainReadAccessor()
    {
        $names = [];
        foreach (AccessorOrder::get() as $order) {
            $names[] = $order->customer_name;
        }

        return response()->json($names);
    }
}
