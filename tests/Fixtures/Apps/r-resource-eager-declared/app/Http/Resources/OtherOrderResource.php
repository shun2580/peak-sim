<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OtherOrderResource extends JsonResource
{
    public function __construct(
        Order $order,
        private readonly Order $other,
    ) {
        parent::__construct($order);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->other->customer->name, // PLANT: a property of the wrapped class that is not the wrapped model
        ];
    }
}
