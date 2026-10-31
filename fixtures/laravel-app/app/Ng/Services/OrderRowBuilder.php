<?php

namespace App\Ng\Services;

use App\Models\Order;

class OrderRowBuilder
{
    public function __construct(private readonly CustomerNameResolver $names) {}

    /** @return array{id: int, customer: string, total: string} */
    public function build(Order $order): array
    {
        return [
            'id' => $order->id,
            'customer' => $this->names->nameOf($order),
            'total' => (string) $order->total,
        ];
    }
}
