<?php

namespace Tests\Feature\Ok;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AssertsGrowth;
use Tests\TestCase;

/**
 * Shared helpers for the /ok group tests: the expected values (fixtures/expected.json),
 * seeding at a given size, and the request helper (the method each route answers to).
 */
abstract class OkRouteTestCase extends TestCase
{
    use AssertsGrowth;
    use RefreshDatabase;

    /** Row counts used for every measurement (three or more points, two orders of magnitude). */
    protected const SCALES = [100, 1000, 10000];

    /** The routes of the group that write and so are POST; every other /ok route is a GET. */
    protected const POST_ROUTES = ['/ok/bulk'];

    /** Call $route with its method (CSRF is off under the test environment, as in any Laravel test). */
    protected function request(string $route): TestResponse
    {
        return in_array($route, self::POST_ROUTES, true) ? $this->post($route) : $this->get($route);
    }

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

    /**
     * One line per measurement on stderr, e.g. "MEASURE peak_bytes /ok/chunk 100=159912 1000=164304 10000=174064".
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
