<?php

namespace Tests\Feature\Ng;

use App\Models\Customer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /ng/save-in-loop: every customer is changed and saved inside a loop. Expected: queries O(N), memory O(N). */
class DeepSaveInLoopTest extends TestCase
{
    use DeepMeasures;
    use RefreshDatabase;

    private function populate(int $customers): void
    {
        DatabaseSeeder::sized(customers: $customers, products: 1, orders: 0, itemsPerOrder: 0)->run();
    }

    public function test_it_changes_and_saves_every_customer(): void
    {
        $this->populate(4);
        $before = Customer::orderBy('id')->pluck('name', 'id')->all();

        $this->getJson('/ng/save-in-loop')->assertOk()->assertJson(['updated' => 4]);

        foreach (Customer::orderBy('id')->get() as $customer) {
            $this->assertNotSame($before[$customer->id], $customer->name);
        }
    }

    public function test_queries_grow_linearly_with_the_customer_count(): void
    {
        $this->assertQueriesGrowLinearly(
            route: '/ng/save-in-loop',
            populate: fn (int $scale) => $this->populate($scale),
            run: fn () => $this->getJson('/ng/save-in-loop')->assertOk(),
            perItem: 1,
        );
    }

    public function test_peak_memory_grows_with_the_customer_count(): void
    {
        $this->assertMemoryGrowsWithRows(
            route: '/ng/save-in-loop',
            populate: fn (int $scale) => $this->populate($scale),
            run: fn () => $this->getJson('/ng/save-in-loop')->assertOk(),
        );
    }
}
