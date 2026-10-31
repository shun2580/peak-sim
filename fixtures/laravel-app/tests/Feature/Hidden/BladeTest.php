<?php

namespace Tests\Feature\Hidden;

use App\Models\Order;

/** /hidden/blade: the relation is read inside the Blade view (expected: queries O(N), memory O(N)). */
class BladeTest extends HiddenRouteTestCase
{
    public function test_it_lists_every_order_with_its_customer(): void
    {
        $this->seedOrders(4);
        $names = Order::with('customer')->orderBy('id')->get()->map(fn (Order $order) => $order->customer->name)->all();

        $response = $this->get('/hidden/blade')->assertOk();
        foreach ($names as $name) {
            $response->assertSee($name);
        }
    }

    public function test_queries_grow_linearly_in_the_number_of_orders(): void
    {
        $this->assertQueryClass('O(N)', $this->measureGet('/hidden/blade', [10, 100, 1000]));

        $this->assertQueryGrowthAtSmallScales('/hidden/blade', 'O(N)', atMostPerRow: 1);
    }

    public function test_memory_grows_linearly_in_the_number_of_orders(): void
    {
        $this->assertMemoryClass('O(N)', $this->measureGet('/hidden/blade', [100, 1000, 10000]));
    }
}
