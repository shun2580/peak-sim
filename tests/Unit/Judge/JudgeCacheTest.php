<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Judge;

use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\EventKind;
use PeakSim\Model\MemoryCause;
use PeakSim\Model\RouteResult;
use PeakSim\Model\Summary;

/** Row (h) of design 3.5: a Cache::remember has a miss view and a hit view, and the miss view is the main one. */
final class JudgeCacheTest extends JudgeCase
{
    /** The shape of /hidden/cache: an N+1 inside the closure (miss) and a restore of every row (hit). */
    private function cached(array $assumptions = [], array $gaps = []): Summary
    {
        $miss = self::miss();
        $hit = self::hit();

        return self::summary([
            $this->event(['kind' => EventKind::Select, 'scenario' => [$miss], 'memory' => self::memory(1, 'orders')]),
            $this->event(['kind' => EventKind::LazyLoad, 'scenario' => [$miss], 'frames' => [self::frame('orders')], 'table' => 'customers']),
            $this->event(['kind' => EventKind::CacheRestore, 'scenario' => [$hit], 'query' => null, 'line' => 70, 'memory' => self::memory(1, 'orders', MemoryCause::CacheRestore)]),
        ], $gaps, $assumptions);
    }

    public function testWithoutACacheThereIsNoCacheView(): void
    {
        self::assertNull(self::judge(self::summary([$this->event()]))->cache);
    }

    public function testMissAndHitAreJudgedSeparatelyAndTheMainVerdictIsTheWorse(): void
    {
        $r = self::judge($this->cached());

        self::assertNotNull($r->cache);
        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->cache->onMiss['query']));
        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->cache->onHit['query']));
        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->cache->onMiss['memory']));
        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->cache->onHit['memory']));
        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->query));
        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->memory));
        self::assertSame('miss', $r->cache->adoptedView);
    }

    public function testTheHitRestoringEveryRowIsReported(): void
    {
        $r = self::judge($this->cached());

        self::assertTrue($r->cache?->restoresAllOnHit);
    }

    public function testAHitThatRestoresNothingIsNotReportedAsRestoringAll(): void
    {
        $r = self::judge(self::summary([
            $this->event(['scenario' => [self::miss()], 'memory' => self::memory(1, 'orders')]),
            $this->event(['kind' => EventKind::CacheRestore, 'scenario' => [self::hit()], 'query' => null, 'memory' => self::memory(0, null, MemoryCause::CacheRestore)]),
        ]));

        self::assertFalse($r->cache?->restoresAllOnHit);
        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->cache->onHit['memory']));
    }

    public function testTheCacheSitesAreTheSitesOfTheRestoreEvents(): void
    {
        $r = self::judge($this->cached());

        self::assertSame(['app/Http/Controllers/OrderController.php:70'], array_map(static fn ($s): string => $s->fingerprint(), $r->cache->sites));
    }

    public function testAnEventWithoutATagBelongsToBothViews(): void
    {
        $r = self::judge(self::summary([
            $this->event(['frames' => [self::frame('orders')]]),
            $this->event(['scenario' => [self::miss()], 'memory' => self::memory(1, 'orders')]),
            $this->event(['kind' => EventKind::CacheRestore, 'scenario' => [self::hit()], 'query' => null, 'memory' => self::memory(0, null, MemoryCause::CacheRestore)]),
        ]));

        self::assertSame('O(N)', $r->cache->onMiss['query']->growth->value);
        self::assertSame('O(N)', $r->cache->onHit['query']->growth->value);
        self::assertSame('O(N)', $r->cache->onMiss['memory']->growth->value);
        self::assertSame('O(1)', $r->cache->onHit['memory']->growth->value);
    }

    public function testR1WhenGrowthIsEqualTheMainConfidenceIsTheMissViews(): void
    {
        // a serialising driver is assumed for the hit only: the main verdict must not be lowered by it
        $r = self::judge($this->cached([self::assumption([Axis::Memory], AssumptionCode::CACHE_DRIVER_SERIALIZING, [self::hit()])]));

        self::assertSame('high', $r->cache->onMiss['memory']->confidence?->value);
        self::assertSame('medium', $r->cache->onHit['memory']->confidence?->value);
        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->memory));
        self::assertSame('high', $r->confidence?->value);
    }

    public function testR1AMissViewAssumptionDoesLowerTheMainConfidence(): void
    {
        $r = self::judge($this->cached([self::assumption([Axis::Memory], AssumptionCode::RELATION_INFERRED, [self::miss()])]));

        self::assertSame('medium', $r->cache->onMiss['memory']->confidence?->value);
        self::assertSame('high', $r->cache->onHit['memory']->confidence?->value);
        self::assertSame(['O(N)', 1, 'medium', null], self::shape($r->memory));
        self::assertSame('medium', $r->confidence?->value);
    }

    public function testWhenGrowthDiffersTheWorseViewDecidesTheWholeVerdictIncludingItsConfidence(): void
    {
        // the hit restores a nested set, the miss is flat; the hit view is the worse one
        $r = self::judge(self::summary([
            $this->event(['scenario' => [self::miss()], 'memory' => self::memory(1, 'orders')]),
            $this->event([
                'kind' => EventKind::CacheRestore,
                'scenario' => [self::hit()],
                'query' => null,
                'memory' => self::memory(2, 'orders', MemoryCause::CacheRestore),
            ]),
        ], [], [self::assumption([Axis::Memory], AssumptionCode::CACHE_DRIVER_SERIALIZING, [self::hit()])]));

        self::assertSame(['O(N*M)', 2, 'medium', null], self::shape($r->memory));
        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->cache->onMiss['memory']));
    }

    public function testAGapInOneViewMakesTheMainAxisUnknown(): void
    {
        $r = self::judge($this->cached([], [self::gap([Axis::Memory], 33, [self::hit()])]));

        self::assertSame('unknown', $r->memory->growth->value);
        self::assertSame('unknown', $r->cache->onHit['memory']->growth->value);
        self::assertSame('O(N)', $r->cache->onMiss['memory']->growth->value);
        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->query));
        self::assertNull($r->confidence);
    }

    public function testNestedTagsBelongToAViewOnlyWhenEveryTagAgrees(): void
    {
        $r = self::judge(self::summary([
            $this->event(['scenario' => [self::miss(1), self::miss(2)], 'frames' => [self::frame('orders')]]),
            $this->event(['scenario' => [self::miss(1), self::hit(2)], 'frames' => [self::frame('orders'), self::frame('items', 'orders')]]),
        ]));

        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->cache->onMiss['query']));
        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->cache->onHit['query']));
        // two cache calls, each with a site: no restore event exists, so the first tagged item gives it
        self::assertCount(2, $r->cache->sites);
    }

    public function testTheCacheViewIsAPlainValueSoItCanBeCompared(): void
    {
        $summary = $this->cached();

        self::assertEquals(self::judge($summary), self::judge($summary));
        self::assertInstanceOf(RouteResult::class, self::judge($summary));
    }
}
