<?php

namespace Tests\Feature\Ng;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Ralder\QueryScaling\QueryGrowth;
use Tests\Support\MeasuresMemory;

/** Shared helpers for the ng-basic tests: expected values, seeding, memory classification. */
trait BasicMeasurement
{
    use MeasuresMemory;

    /** Memory growth is classified from the log-log slope of the last two scales (fixed up front). */
    private const MEMORY_LINEAR_MIN_SLOPE = 0.5;

    private const MEMORY_CONSTANT_MAX_SLOPE = 0.2;

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

    protected function queryExpectation(string $growth, string $label): BasicRecordingExpectation
    {
        $inner = match ($growth) {
            'O(1)' => QueryGrowth::constant(),
            'O(N)', 'O(N*M)' => new BasicGrowsLinearly,
            default => $this->fail("unexpected query growth {$growth} in expected.json"),
        };

        return new BasicRecordingExpectation($inner, $label);
    }

    /** @param  list<int>  $scales  @param  list<int>  $peaks */
    protected function memoryClass(array $scales, array $peaks): string
    {
        $n = count($scales);
        $slope = log($peaks[$n - 1] / $peaks[$n - 2]) / log($scales[$n - 1] / $scales[$n - 2]);

        return match (true) {
            $slope >= self::MEMORY_LINEAR_MIN_SLOPE => 'linear',
            $slope <= self::MEMORY_CONSTANT_MAX_SLOPE => 'constant',
            default => 'inconclusive',
        };
    }

    /** @param  list<int>  $scales  @param  list<int>  $peaks */
    protected function logMemory(string $label, array $scales, array $peaks): void
    {
        fwrite(STDERR, sprintf(
            "[measure] memory %s scales=[%s] peak_bytes=[%s] class=%s\n",
            $label,
            implode(',', $scales),
            implode(',', $peaks),
            $this->memoryClass($scales, $peaks),
        ));
    }
}
