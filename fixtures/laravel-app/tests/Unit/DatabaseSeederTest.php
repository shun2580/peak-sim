<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_requested_sizes_and_leaves_product_stats_empty(): void
    {
        DatabaseSeeder::sized(customers: 7, products: 5, orders: 30, itemsPerOrder: 4)->run();

        $this->assertSame(7, DB::table('customers')->count());
        $this->assertSame(5, DB::table('products')->count());
        $this->assertSame(30, DB::table('orders')->count());
        $this->assertSame(120, DB::table('items')->count());
        $this->assertSame(0, DB::table('product_stats')->count());
    }

    public function test_every_order_has_the_requested_number_of_items_and_a_matching_total(): void
    {
        DatabaseSeeder::sized(customers: 3, products: 4, orders: 10, itemsPerOrder: 2)->run();

        foreach (Order::with('items')->get() as $order) {
            $this->assertCount(2, $order->items);
            $sum = $order->items->sum(fn (Item $item) => $item->quantity * (float) $item->price);
            $this->assertEqualsWithDelta($sum, (float) $order->total, 0.001);
        }
    }

    public function test_the_same_sizes_produce_the_same_rows(): void
    {
        DatabaseSeeder::sized(5, 5, 20, 3)->run();
        $first = $this->itemsSnapshot();

        DB::table('items')->delete();
        DB::table('orders')->delete();
        DB::table('products')->delete();
        DB::table('customers')->delete();

        DatabaseSeeder::sized(5, 5, 20, 3)->run();

        $this->assertSame($first, $this->itemsSnapshot());
    }

    private function itemsSnapshot(): string
    {
        return DB::table('items')
            ->select('order_id', 'product_id', 'quantity', 'price')
            ->orderBy('order_id')->orderBy('product_id')->orderBy('quantity')
            ->get()->toJson();
    }
}
