<?php

namespace Tests\Feature\Hidden;

use App\Models\Order;
use Ralder\QueryScaling\QueryGrowth;

/** /hidden/appends: the relation is read in an accessor listed in $appends (expected: queries O(N), memory O(N)). */
class AppendsTest extends HiddenRouteTestCase
{
    public function test_it_lists_every_order_with_its_customer(): void
    {
        $this->seedOrders(4);
        $names = Order::with('customer')->orderBy('id')->get()->map(fn (Order $order) => $order->customer->name)->all();

        $this->get('/hidden/appends')->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.customer_name', $names[0])
            ->assertJsonPath('3.customer_name', $names[3]);
    }

    public function test_queries_grow_linearly_in_the_number_of_orders(): void
    {
        $this->assertQueryClass('O(N)', $this->measureGet('/hidden/appends', [10, 100, 1000]));

        $this->assertQueryScaling(
            scales: [5, 10, 20],
            expectation: QueryGrowth::atMostSlope(1, 0),
            populate: fn (int $scale) => $this->seedOrders($scale),
            run: fn () => $this->get('/hidden/appends')->assertOk(),
        );
    }

    public function test_memory_grows_linearly_in_the_number_of_orders(): void
    {
        $this->assertMemoryClass('O(N)', $this->measureGet('/hidden/appends', [100, 1000, 10000]));
    }
}
