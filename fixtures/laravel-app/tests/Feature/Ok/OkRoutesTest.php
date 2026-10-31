<?php

namespace Tests\Feature\Ok;

use App\Http\Controllers\Ok\OrderController;
use App\Models\Order;
use Database\Seeders\DatabaseSeeder;

/** What each /ok route returns and does, on a small data set (customers 7, products 5, orders 250, 3 items each). */
class OkRoutesTest extends OkRouteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DatabaseSeeder::sized(customers: 7, products: 5, orders: 250, itemsPerOrder: 3)->run();
    }

    public function test_eager_lists_every_order_with_its_customer(): void
    {
        $rows = $this->get('/ok/eager')->assertOk()->json();

        $this->assertCount(250, $rows);
        $this->assertSame(1, $rows[0]['id']);
        $this->assertStringStartsWith('Customer ', $rows[0]['customer']);
    }

    public function test_paginate_returns_one_page_only(): void
    {
        $page = $this->get('/ok/paginate')->assertOk()->json();

        $this->assertCount(OrderController::PER_PAGE, $page['data']);
        $this->assertSame(250, $page['total']);
    }

    public function test_chunk_visits_every_order(): void
    {
        $this->get('/ok/chunk')->assertOk()->assertJson([
            'count' => 250,
            'total' => round((float) Order::sum('total'), 2),
        ]);
    }

    public function test_lazy_visits_every_order(): void
    {
        $this->get('/ok/lazy')->assertOk()->assertJson([
            'count' => 250,
            'total' => round((float) Order::sum('total'), 2),
        ]);
    }

    public function test_with_count_returns_the_item_aggregates_per_order(): void
    {
        $rows = $this->get('/ok/with-count')->assertOk()->json();

        $this->assertCount(250, $rows);
        $this->assertSame(3, $rows[0]['items_count']);
        $this->assertGreaterThan(0, $rows[0]['items_sum_quantity']);
    }

    public function test_bulk_creates_one_order_per_customer_at_once(): void
    {
        $before = Order::count();

        $this->get('/ok/bulk')->assertOk()->assertJson(['created' => 7]);

        $this->assertSame($before + 7, Order::count());
        $this->assertSame(7, Order::where('status', 'pending')->where('total', 0)->distinct('customer_id')->count('customer_id'));
    }

    public function test_unknown_dynamic_answers_200(): void
    {
        $this->get('/unknown/dynamic')->assertOk();
        $this->get('/unknown/dynamic?method=latest')->assertOk();
    }

    public function test_unknown_dynamic_refuses_a_method_that_is_not_listed(): void
    {
        $this->get('/unknown/dynamic?method=__construct')->assertNotFound();
    }
}
