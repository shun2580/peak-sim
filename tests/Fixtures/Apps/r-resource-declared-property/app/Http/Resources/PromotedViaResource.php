<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotedViaResource extends JsonResource
{
    public function __construct(
        private readonly Order $order,
    ) {
        parent::__construct($order);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'customer' => $this->resource->customer->name, // PLANT: lazy load through $this->resource: the control
        ];
    }
}
