<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WrappedSecondResource extends JsonResource
{
    public function __construct(
        Order $first,
        private readonly Order $order,
    ) {
        parent::__construct($order);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->order->customer->name, // PLANT: the wrapped model is the second argument, not the first
        ];
    }
}
