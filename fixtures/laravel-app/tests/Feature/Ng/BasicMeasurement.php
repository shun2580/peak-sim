<?php

namespace Tests\Feature\Ng;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsGrowth;

/** Shared helpers for the ng-basic tests: expected values and seeding. Growth is judged by GrowthRules (D26). */
trait BasicMeasurement
{
    use AssertsGrowth;

    /** @return array<string, mixed> the row of fixtures/expected.json for this route */
    protected function expectedFor(string $route): array
    {
        $all = json_decode(file_get_contents(base_path('../expected.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($all['routes'] as $row) {
            if ($row['route'] === $route) {
                return $row;
            }
        }

        $this->fail("{$route} is not in fixtures/expected.json");
    }

    protected function seedOrders(int $orders, int $itemsPerOrder = 3): void
    {
        DatabaseSeeder::sized(customers: 10, products: 20, orders: $orders, itemsPerOrder: $itemsPerOrder)->run();
    }

    protected function clearOrders(): void
    {
        foreach (['items', 'orders', 'products', 'customers'] as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * The growth to hold a sweep over the orders to. O(N*M) is checked by its two sweeps elsewhere;
     * the sweep over the orders alone has to be O(N).
     *
     * @return 'O(1)'|'O(N)'
     */
    protected function orderSweepGrowth(string $growth): string
    {
        return match ($growth) {
            'O(1)' => 'O(1)',
            'O(N)', 'O(N*M)' => 'O(N)',
            default => $this->fail("unexpected growth {$growth} in expected.json"),
        };
    }
}
