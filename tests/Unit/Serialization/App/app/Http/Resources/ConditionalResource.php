<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConditionalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->when(true, fn () => $this->customer->name),
            $this->mergeWhen(true, fn () => ['count' => count($this->items)]),
        ];
    }
}
