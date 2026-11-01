<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnlessFlagResource extends JsonResource
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
            'customer' => $this->unless(false, $this->order->customer->name), // PLANT: lazy load as the value argument of unless()
        ];
    }
}
