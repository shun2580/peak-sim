<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropResource extends JsonResource
{
    private Order $order;

    public function __construct(Order $order)
    {
        parent::__construct($order);
        $this->order = $order;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->order->id,
            'customer' => $this->order->customer->name, // PLANT: lazy load through a declared (not promoted) property
        ];
    }
}
