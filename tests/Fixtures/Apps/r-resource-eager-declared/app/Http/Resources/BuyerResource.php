<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BuyerResource extends JsonResource
{
    public function __construct(
        Order $order,
        private readonly Customer $buyer,
    ) {
        parent::__construct($order);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orders' => $this->buyer->orders->count(), // PLANT: a property of another class than the wrapped model
        ];
    }
}
