<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnlessFlagPlainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->unless(false, $this->customer->name), // PLANT: lazy load as the value argument of unless(), no ctor: the control
        ];
    }
}
