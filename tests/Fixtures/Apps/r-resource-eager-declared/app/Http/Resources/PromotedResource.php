<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotedResource extends JsonResource
{
    public function __construct(
        private readonly Order $order,
    ) {
        parent::__construct($order);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->order->id,
            'customer' => $this->order->customer->name, // PLANT: relation read through a promoted ctor property
        ];
    }
}
