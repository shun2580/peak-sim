<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;

class QueryController
{
    public function nPlusOne(): void
    {
        foreach (Order::all() as $order) {
            $order->customer;
        }
    }

    public function eagerIsFine(): void
    {
        foreach (Order::with('customer')->get() as $order) {
            $order->customer;
        }
    }

    public function nested(): void
    {
        foreach (Order::all() as $order) {
            foreach ($order->items as $item) {
                $item->product;
            }
        }
    }

    public function countInLoop(): void
    {
        foreach (Order::all() as $order) {
            $order->items()->count();
        }
    }

    public function chunked(): void
    {
        $count = 0;
        Order::chunk(100, function ($orders) use (&$count) {
            foreach ($orders as $order) {
                $count++;
            }
        });
    }

    public function chunkedWithLookup(): void
    {
        Order::chunk(100, function ($orders) {
            foreach ($orders as $order) {
                $order->customer;
            }
        });
    }

    public function lazily(): void
    {
        foreach (Order::lazy(100) as $order) {
            $order->customer;
        }
    }

    public function cursored(): void
    {
        foreach (Order::cursor() as $order) {
            $order->total;
        }
    }

    public function paged(): void
    {
        Order::paginate(15);
    }

    public function bulk(): void
    {
        $ids = Customer::pluck('id');
        Order::insert($ids);
    }

    public function modelWith(): void
    {
        \App\Models\WithOrder::get();
    }

    public function saveInLoop(): void
    {
        foreach (Customer::all() as $customer) {
            $customer->save();
        }
    }
}
