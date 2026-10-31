<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ForwardingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'label' => $this->label(),
            'items' => $this->items()->count(),
            'shout' => $this->shout(),
        ];
    }

    private function label(): string
    {
        return $this->customer->name;
    }
}
