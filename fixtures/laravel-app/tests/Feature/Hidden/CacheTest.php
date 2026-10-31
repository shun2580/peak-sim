<?php

namespace Tests\Feature\Hidden;

use App\Http\Controllers\Hidden\CacheController;
use Illuminate\Support\Facades\Cache;

/**
 * /hidden/cache: the N+1 is wrapped in Cache::remember (store: array, as in phpunit.xml).
 * Expected: queries O(N) on a miss, O(1) on a hit; memory O(N) on a hit too (all rows are restored).
 */
class CacheTest extends HiddenRouteTestCase
{
    public function test_a_hit_returns_the_same_rows_as_the_miss(): void
    {
        $this->seedOrders(4);
        Cache::flush();

        $miss = $this->get('/hidden/cache')->assertOk()->assertJsonCount(4);
        $this->assertTrue(Cache::has(CacheController::KEY));
        $hit = $this->get('/hidden/cache')->assertOk();

        $this->assertSame($miss->getContent(), $hit->getContent());
    }

    public function test_queries_grow_on_a_miss_and_stay_constant_on_a_hit(): void
    {
        $results = $this->measure('/hidden/cache', [10, 100, 1000], $this->missThenHit());

        $this->assertQueryClass('O(N)', $results, 'miss');
        $this->assertQueryClass('O(1)', $results, 'hit');

        $this->assertQueryGrowthAtSmallScales(
            '/hidden/cache',
            'O(N)',
            populate: function (int $scale): void {
                $this->seedOrders($scale);
                Cache::flush();
            },
            atMostPerRow: 1,
        );
        $this->assertQueryGrowthAtSmallScales(
            '/hidden/cache (hit)',
            'O(1)',
            populate: function (int $scale): void {
                $this->seedOrders($scale);
                Cache::flush();
            },
            run: fn () => $this->get('/hidden/cache')->assertOk(),
            warmup: fn () => $this->get('/hidden/cache')->assertOk(),
            allowNoQueries: true,
        );
    }

    public function test_memory_grows_with_the_number_of_orders_on_a_hit_as_well(): void
    {
        $results = $this->measure('/hidden/cache', [100, 1000, 10000], $this->missThenHit());

        $this->assertMemoryClass('O(N)', $results, 'miss');
        $this->assertMemoryClass('O(N)', $results, 'hit');
    }

    public function test_restoring_the_cached_rows_alone_grows_with_the_number_of_orders(): void
    {
        // The array store keeps PHP values as they are by default; real stores (file, redis, database)
        // serialize, so a hit unserializes every row. Measure the restore without the response body.
        config(['cache.stores.array.serialize' => true]);
        Cache::forgetDriver('array');

        $results = $this->measure('/hidden/cache (restore only)', [100, 1000, 10000], ['restore' => [
            'prepare' => function (): void {
                Cache::flush();
                $this->get('/hidden/cache')->assertOk();
            },
            'work' => fn () => Cache::get(CacheController::KEY),
        ]]);

        $this->assertMemoryClass('O(N)', $results, 'restore');
    }

    /** @return array<string, array{work: \Closure, prepare?: \Closure}> */
    private function missThenHit(): array
    {
        return [
            'miss' => ['prepare' => fn () => Cache::flush(), 'work' => fn () => $this->get('/hidden/cache')->assertOk()],
            'hit' => ['work' => fn () => $this->get('/hidden/cache')->assertOk()],
        ];
    }
}
