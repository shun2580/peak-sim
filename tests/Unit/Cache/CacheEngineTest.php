<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Cache;

use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Confidence;
use PeakSim\Model\Growth;
use PeakSim\Model\RouteResult;
use PeakSim\Tests\Unit\Known\JudgesRoutes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cache::remember behind the real engine and the real Judge: the route's two views (miss and hit) and the main
 * verdict, as the k-cache-* mini-apps expect them.
 */
final class CacheEngineTest extends TestCase
{
    use JudgesRoutes;

    private const NAMES = <<<'PHP'
            $names = %s fn () => Order::get()
                ->map(fn (Order $order) => $order->customer->name));

            return response()->json($names);
    PHP;

    private function assertView(RouteResult $r, string $side, Growth $query, Growth $memory): void
    {
        $this->assertNotNull($r->cache);
        $view = $side === 'miss' ? $r->cache->onMiss : $r->cache->onHit;
        $this->assertSame([$query, $memory], [$view['query']->growth, $view['memory']->growth], $side);
    }

    /** @return array<string, array{0: string}> */
    public static function nPlusOneSpellings(): array
    {
        return [
            'Cache::remember' => ["Cache::remember('orders.names', 300,"],
            'Cache::rememberForever' => ["Cache::rememberForever('orders.names',"],
            'Cache::sear' => ["Cache::sear('orders.names',"],
            'Cache::flexible' => ["Cache::flexible('orders.names', [60, 120],"],
            'the cache() helper' => ["cache()->remember('orders.names', 300,"],
        ];
    }

    #[DataProvider('nPlusOneSpellings')]
    public function testACachedNPlusOneIsLinearOnAMissAndOnlyTheRestoreGrowsOnAHit(string $call): void
    {
        $r = $this->judge(sprintf(self::NAMES, $call), 'use Illuminate\Support\Facades\Cache;');

        $this->assertSame(Growth::Linear, $r->query->growth);
        $this->assertSame(Growth::Linear, $r->memory->growth);
        $this->assertSame(Confidence::High, $r->confidence, 'the driver assumption concerns the hit view only');
        $this->assertSame('orders', $r->nTable);
        $this->assertSame([], $this->gapCodes($r));
        $this->assertView($r, 'miss', Growth::Linear, Growth::Linear);
        $this->assertView($r, 'hit', Growth::Constant, Growth::Linear);
        $this->assertTrue($r->cache?->restoresAllOnHit);
        $this->assertSame('miss', $r->cache?->adoptedView);
        $this->assertContains(AssumptionCode::CACHE_DRIVER_SERIALIZING->value, array_map(static fn ($a): string => $a->code->value, $r->assumptions));
        $this->assertSame([], $this->engine?->violations());
    }

    public function testAnInjectedRepositoryIsFollowedToo(): void
    {
        $r = $this->judge(<<<'PHP'
                $names = $cache->remember('orders.names', 300, fn () => Order::get()
                    ->map(fn (Order $order) => $order->customer->name));

                return response()->json($names);
        PHP, 'use Illuminate\Contracts\Cache\Repository;', 'Repository $cache');

        $this->assertView($r, 'miss', Growth::Linear, Growth::Linear);
        $this->assertView($r, 'hit', Growth::Constant, Growth::Linear);
        $this->assertSame([], $this->gapCodes($r));
    }

    public function testACachedScalarIsConstantOnBothSidesAndRestoresNothingInFull(): void
    {
        $r = $this->judge(<<<'PHP'
                $first = Cache::remember('orders.first', 60, fn () => Order::first());

                return response()->json(['total' => $first]);
        PHP, 'use Illuminate\Support\Facades\Cache;');

        $this->assertSame(Growth::Constant, $r->query->growth);
        $this->assertSame(Growth::Constant, $r->memory->growth);
        $this->assertView($r, 'miss', Growth::Constant, Growth::Constant);
        $this->assertView($r, 'hit', Growth::Constant, Growth::Constant);
        $this->assertFalse($r->cache?->restoresAllOnHit);
        $this->assertSame([], $r->assumptions);
        $this->assertSame([], $this->gapCodes($r));
        $this->assertSame(Confidence::High, $r->confidence);
    }

    public function testACacheLookupPerRowMissesAtNAndHitsAtOneQueryButStillHoldsTheRows(): void
    {
        $r = $this->judge(<<<'PHP'
                $names = [];
                foreach (Order::get() as $order) {
                    $names[] = Cache::remember('customer.name.' . $order->id, 300, fn () => $order->customer->name);
                }

                return response()->json($names);
        PHP, 'use Illuminate\Support\Facades\Cache;');

        $this->assertSame(Growth::Linear, $r->query->growth);
        $this->assertSame(Growth::Linear, $r->memory->growth);
        $this->assertSame('orders', $r->nTable);
        $this->assertView($r, 'miss', Growth::Linear, Growth::Linear);
        $this->assertView($r, 'hit', Growth::Constant, Growth::Linear);
        $this->assertFalse($r->cache?->restoresAllOnHit, 'one string per row is not restored in full');
        $this->assertSame([], $this->gapCodes($r));
        $this->assertSame([], $this->engine?->violations());
    }

    public function testAClosureThatCannotBeFollowedMakesBothViewsUnknown(): void
    {
        $r = $this->judge(<<<'PHP'
                $rows = Cache::remember('rows', 300, fn () => undefined_helper());

                return response()->json($rows);
        PHP, 'use Illuminate\Support\Facades\Cache;');

        $this->assertSame(Growth::Unknown, $r->query->growth);
        $this->assertSame(Growth::Unknown, $r->memory->growth);
        $this->assertNull($r->confidence);
        $this->assertContains('CACHE_VALUE_SIZE_UNKNOWN', $this->gapCodes($r));
        $this->assertContains('VENDOR_CALL_NOT_MODELLED', $this->gapCodes($r));
        $this->assertView($r, 'miss', Growth::Unknown, Growth::Unknown);
        $this->assertSame(Growth::Unknown, $r->cache?->onHit['memory']->growth);
        $this->assertSame([], $this->engine?->violations());
    }

    public function testWhatIsCachedKeepsItsOriginForTheLoopThatFollows(): void
    {
        $r = $this->judge(<<<'PHP'
                $orders = Cache::remember('orders', 60, fn () => Order::get());
                foreach ($orders as $order) {
                    $order->customer;
                }

                return response()->json(['ok' => true]);
        PHP, 'use Illuminate\Support\Facades\Cache;');

        $this->assertSame(Growth::Linear, $r->query->growth);
        $this->assertView($r, 'miss', Growth::Linear, Growth::Linear);
        $this->assertView($r, 'hit', Growth::Linear, Growth::Linear);
        $this->assertSame('orders', $r->nTable);
        $this->assertTrue($r->cache?->restoresAllOnHit);
        $this->assertSame([], $this->gapCodes($r));
    }

    public function testARowSetCachedWithItsToManyRelationRestoresNTimesM(): void
    {
        $r = $this->judge(<<<'PHP'
                $orders = Cache::remember('orders', 60, fn () => Order::with('items')->get());

                return response()->json(['n' => count($orders)]);
        PHP, 'use Illuminate\Support\Facades\Cache;');

        $this->assertView($r, 'miss', Growth::Constant, Growth::Linear);
        $this->assertView($r, 'hit', Growth::Constant, Growth::Nested);
        $this->assertSame(Growth::Nested, $r->memory->growth, 'the hit is the worse view, and it is the main verdict');
        $this->assertTrue($r->cache?->restoresAllOnHit);
        $this->assertSame([], $this->gapCodes($r));
    }
}
