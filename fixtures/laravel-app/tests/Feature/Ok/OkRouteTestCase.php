<?php

namespace Tests\Feature\Ok;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MeasuresMemory;
use Tests\TestCase;

/**
 * Shared helpers for the /ok group tests: the expected values (fixtures/expected.json),
 * seeding at a given size, and a plain query counter used to report the numbers.
 */
abstract class OkRouteTestCase extends TestCase
{
    use MeasuresMemory;
    use RefreshDatabase;

    /** Row counts used for every measurement (three or more points, two orders of magnitude). */
    protected const SCALES = [100, 1000, 10000];

    /** @return array<string, mixed> the entry of fixtures/expected.json for $route */
    protected function expected(string $route): array
    {
        $all = json_decode(file_get_contents(base_path('../expected.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($all['routes'] as $entry) {
            if ($entry['route'] === $route) {
                return $entry;
            }
        }

        $this->fail("{$route} is not in expected.json");
    }

    /** Seed so that the N table of $route holds $scale rows. Other tables stay small and fixed. */
    protected function seedFor(string $route, int $scale): void
    {
        $table = $this->expected($route)['n_table'];

        DatabaseSeeder::sized(
            customers: $table === 'customers' ? $scale : 20,
            products: 20,
            orders: $table === 'orders' ? $scale : ($table === 'customers' ? 0 : 50),
            itemsPerOrder: 3,
        )->run();
    }

    /**
     * Run $work at $scale rows inside a transaction that is rolled back afterwards,
     * so the next scale starts from an empty database.
     */
    protected function atScale(string $route, int $scale, callable $work): mixed
    {
        DB::beginTransaction();

        try {
            $this->seedFor($route, $scale);

            return $work();
        } finally {
            DB::rollBack();
        }
    }

    /** Number of queries $work issues. */
    protected function countQueries(callable $work): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $work();

        return $count;
    }

    /**
     * One line per measurement on stderr, e.g. "MEASURE queries /ok/chunk 100=2 1000=11 10000=101".
     * Run phpunit directly (vendor/bin/phpunit) to see them; they are what the measurement report quotes.
     *
     * @param  array<int, int|float>  $byScale
     */
    protected function report(string $kind, string $route, array $byScale): void
    {
        $cells = [];
        foreach ($byScale as $scale => $value) {
            $cells[] = "{$scale}={$value}";
        }

        fwrite(STDERR, sprintf("MEASURE %s %s %s\n", $kind, $route, implode(' ', $cells)));
    }
}
