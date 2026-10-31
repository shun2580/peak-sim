<?php

namespace Tests\Feature\Ng;

use App\Models\Product;
use App\Models\ProductStat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /ng/first-or-create-in-loop: one lookup-then-write per product, 2 queries each. Expected: queries O(N) at 2 per row, memory O(N).
 *  - default: firstOrCreate on an empty product_stats (SELECT + INSERT)
 *  - ?via=update: updateOrCreate on a filled product_stats with a changed value (SELECT + UPDATE)
 */
class DeepFirstOrCreateInLoopTest extends TestCase
{
    use DeepMeasures;
    use RefreshDatabase;

    private function populate(int $products): void
    {
        DatabaseSeeder::sized(customers: 1, products: $products, orders: 0, itemsPerOrder: 0)->run();
    }

    /** One product_stats row per product, units_sold = 0 (the route sets a different value). */
    private function populateWithStats(int $products): void
    {
        $this->populate($products);
        DB::table('product_stats')->insert(
            Product::orderBy('id')->pluck('id')->map(fn (int $id) => [
                'product_id' => $id, 'units_sold' => 0, 'created_at' => now(), 'updated_at' => now(),
            ])->all()
        );
    }

    public function test_first_or_create_makes_one_stat_row_per_product(): void
    {
        $this->populate(4);

        $this->getJson('/ng/first-or-create-in-loop')->assertOk()->assertJson(['processed' => 4]);

        $this->assertSame(4, ProductStat::count());
        $this->assertSame([1, 2, 3, 4], ProductStat::orderBy('product_id')->pluck('product_id')->all());
    }

    public function test_update_or_create_changes_the_existing_stat_rows(): void
    {
        $this->populateWithStats(4);

        $this->getJson('/ng/first-or-create-in-loop?via=update')->assertOk()->assertJson(['processed' => 4]);

        $this->assertSame(4, ProductStat::count());
        $this->assertSame(0, ProductStat::where('units_sold', 0)->count());
    }

    public function test_first_or_create_queries_grow_two_per_product(): void
    {
        $this->assertQueriesGrowLinearly(
            route: '/ng/first-or-create-in-loop',
            populate: fn (int $scale) => $this->populate($scale),
            run: fn () => $this->getJson('/ng/first-or-create-in-loop')->assertOk(),
            perItem: 2,
            exact: true,
        );
    }

    public function test_update_or_create_queries_grow_two_per_product(): void
    {
        $this->assertQueriesGrowLinearly(
            route: '/ng/first-or-create-in-loop?via=update',
            populate: fn (int $scale) => $this->populateWithStats($scale),
            run: fn () => $this->getJson('/ng/first-or-create-in-loop?via=update')->assertOk(),
            perItem: 2,
            exact: true,
        );
    }

    public function test_first_or_create_peak_memory_grows_with_the_product_count(): void
    {
        $this->assertMemoryGrowsWithRows(
            route: '/ng/first-or-create-in-loop',
            populate: fn (int $scale) => $this->populate($scale),
            run: fn () => $this->getJson('/ng/first-or-create-in-loop')->assertOk(),
        );
    }

    public function test_update_or_create_peak_memory_grows_with_the_product_count(): void
    {
        $this->assertMemoryGrowsWithRows(
            route: '/ng/first-or-create-in-loop?via=update',
            populate: fn (int $scale) => $this->populateWithStats($scale),
            run: fn () => $this->getJson('/ng/first-or-create-in-loop?via=update')->assertOk(),
        );
    }
}
