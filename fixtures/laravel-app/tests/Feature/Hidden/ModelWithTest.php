<?php

namespace Tests\Feature\Hidden;

use App\Models\Order;

/** /hidden/model-with: the model declares $with (expected: queries O(1) with one extra query per request, memory O(N)). */
class ModelWithTest extends HiddenRouteTestCase
{
    public function test_it_lists_every_order_with_its_customer(): void
    {
        $this->seedOrders(4);
        $names = Order::with('customer')->orderBy('id')->get()->map(fn (Order $order) => $order->customer->name)->all();

        $this->get('/hidden/model-with')->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.customer.name', $names[0])
            ->assertJsonPath('3.customer.name', $names[3]);
    }

    public function test_queries_stay_constant_in_the_number_of_orders(): void
    {
        $this->assertQueryClass('O(1)', $this->measureGet('/hidden/model-with', [10, 100, 1000]));

        $this->assertQueriesScaleConstantly(
            scales: [5, 10, 20],
            populate: fn (int $scale) => $this->seedOrders($scale),
            run: fn () => $this->get('/hidden/model-with')->assertOk(),
        );
    }

    public function test_memory_grows_linearly_in_the_number_of_orders(): void
    {
        $this->assertMemoryClass('O(N)', $this->measureGet('/hidden/model-with', [100, 1000, 10000]));
    }

    public function test_the_declared_relation_costs_exactly_one_extra_query_per_request(): void
    {
        // One query for the orders plus one eager-load for the customers, at every size.
        $results = $this->measureGet('/hidden/model-with', [10, 100, 1000]);

        $this->assertSame([10 => 2, 100 => 2, 1000 => 2], $this->series($results, 'request', 'queries'));
    }
}
