<?php

namespace Tests\Feature\Ng;

use App\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /ng/service: an N+1 (order -> customer) hidden behind several layers. Expected: queries O(N), memory O(N). */
class DeepServiceTest extends TestCase
{
    use DeepMeasures;
    use RefreshDatabase;

    private function populate(int $orders): void
    {
        DatabaseSeeder::sized(customers: 3, products: 3, orders: $orders, itemsPerOrder: 2)->run();
    }

    public function test_it_lists_every_order_with_its_customer_name(): void
    {
        $this->populate(6);

        $rows = $this->getJson('/ng/service')->assertOk()->json('orders');

        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            $order = Order::with('customer')->findOrFail($row['id']);
            $this->assertSame($order->customer->name, $row['customer']);
        }
    }

    public function test_queries_grow_linearly_with_the_order_count(): void
    {
        $this->assertQueriesGrowLinearly(
            route: '/ng/service',
            populate: fn (int $scale) => $this->populate($scale),
            run: fn () => $this->getJson('/ng/service')->assertOk(),
            perItem: 1,
        );
    }

    public function test_peak_memory_grows_with_the_order_count(): void
    {
        $this->assertMemoryGrowsWithRows(
            route: '/ng/service',
            populate: fn (int $scale) => $this->populate($scale),
            run: fn () => $this->getJson('/ng/service')->assertOk(),
        );
    }
}
