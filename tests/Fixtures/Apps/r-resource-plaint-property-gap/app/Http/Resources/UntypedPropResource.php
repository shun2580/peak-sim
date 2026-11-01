<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UntypedPropResource extends JsonResource
{
    private $order;

    public function __construct($order)
    {
        parent::__construct($order);
        $this->order = $order;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->order->customer->name, // PLANT: the property has no declared type, so what it holds is not known
        ];
    }
}
