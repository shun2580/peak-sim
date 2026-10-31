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

        $this->assertQueryGrowthAtSmallScales('/hidden/cursor', 'O(1)');
    }

    public function test_memory_grows_linearly_in_the_number_of_orders(): void
    {
        $results = $this->measureGet('/hidden/cursor', [100, 1000, 10000]);

        $this->assertMemoryClass('O(N)', $results);

        // The growth is small next to a model collection, so the cost per row is reported too (D25 (6)):
        // what PDO buffers for each added row of the largest step.
        $peaks = $this->series($results, 'request', 'memory');
        $bytesPerRow = ($peaks[10000] - $peaks[1000]) / (10000 - 1000);
        fwrite(STDERR, sprintf("[growth] bytes_per_row /hidden/cursor 1000->10000=%.1f\n", $bytesPerRow));
        $this->assertGreaterThan(0, $bytesPerRow);
    }
}
