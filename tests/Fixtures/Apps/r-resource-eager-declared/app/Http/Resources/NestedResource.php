<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NestedResource extends JsonResource
{
    public function __construct(
        private readonly Order $order,
    ) {
        parent::__construct($order);
    }

    public function toArray(Request $request): array
    {
        $names = [];
        foreach ($this->order->items as $item) { // PLANT: nested relation read through a promoted property
            $names[] = $item->product->name;
        }

        return [
            'id' => $this->order->id,
            'products' => $names,
        ];
    }
}
