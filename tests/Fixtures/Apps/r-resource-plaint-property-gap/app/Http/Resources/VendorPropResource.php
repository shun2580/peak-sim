<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorPropResource extends JsonResource
{
    public function __construct(
        private readonly Order $order,
        private readonly \Stripe\StripeClient $client,
    ) {
        parent::__construct($order);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->order->id,
            'charge' => $this->client->charge($this->order->id), // PLANT: a call on a vendor object nobody modelled
        ];
    }
}
