<?php

namespace Tests\Feature\Hidden;

use App\Models\Order;

/** /hidden/cursor: Order::cursor() (expected: queries O(1), memory O(N) because PDO buffers every row). */
class CursorTest extends HiddenRouteTestCase
{
    public function test_it_lists_every_order_with_its_customer(): void
    {
        $this->seedOrders(4);
        $names = Order::with('customer')->orderBy('id')->get()->map(fn (Order $order) => $order->customer->name)->all();

        $response = $this->get('/hidden/cursor')->assertOk();
        $this->assertSame(4, $response->json('count'));
        $this->assertEqualsWithDelta(Order::sum('total'), $response->json('total'), 0.01);
    }

    public function test_queries_stay_constant_in_the_number_of_orders(): void
    {
        $this->assertQueryClass('O(1)', $this->measureGet('/hidden/cursor', [10, 100, 1000]));

        $this->assertQueriesScaleConstantly(
            scales: [5, 10, 20],
            populate: fn (int $scale) => $this->seedOrders($scale),
            run: fn () => $this->get('/hidden/cursor')->assertOk(),
        );
    }

    public function test_memory_grows_linearly_in_the_number_of_orders(): void
    {
        $this->assertMemoryClass('O(N)', $this->measureGet('/hidden/cursor', [100, 1000, 10000]));
    }
}
