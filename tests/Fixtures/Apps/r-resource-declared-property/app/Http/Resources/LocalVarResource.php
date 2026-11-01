<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocalVarResource extends JsonResource
{
    public function __construct(
        private readonly Order $order,
    ) {
        parent::__construct($order);
    }

    public function toArray(Request $request): array
    {
        $user = $this->order->customer; // PLANT: lazy load into a local variable

        return [
            'id' => $this->order->id,
            'customer' => $user->name,
        ];
    }
}
