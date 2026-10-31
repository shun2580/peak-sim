<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Judge;

use PeakSim\Model\AssumptionCode;
use PeakSim\Model\Axis;
use PeakSim\Model\EventKind;
use PeakSim\Model\MemoryCause;

/** The hand-built table of design 3.5, rows (a) to (g). */
final class JudgeAxesTest extends JudgeCase
{
    public function testAnEmptySummaryIsConstantAndSaysNothingWasThere(): void
    {
        $r = self::judge(self::summary());

        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->query));
        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->memory));
        self::assertSame(0, $r->eventsFound);
        self::assertSame('high', $r->confidence?->value);
        self::assertNull($r->nTable);
        self::assertNull($r->mTable);
        self::assertNull($r->mPer);
        self::assertNull($r->cache);
        self::assertNull($r->worstCase);
        self::assertSame([], $r->gaps);
        self::assertFalse($r->truncated);
    }

    public function testAGapAloneMakesBothAxesUnknownWithNoLowerBound(): void
    {
        $gap = self::gap(null, 31, [], 3);

        $r = self::judge(self::summary([], [$gap]));

        self::assertSame(['unknown', 0, null, null], self::shape($r->query));
        self::assertSame(['unknown', 0, null, null], self::shape($r->memory));
        self::assertNull($r->confidence);
        self::assertSame(0, $r->eventsFound);
        self::assertSame([$gap], $r->gaps);
        self::assertSame('app/Http/Controllers/OrderController.php:31', $r->query->dominantSite?->fingerprint());
    }

    public function testTheLoopsAroundAGapAddNothingToTheLowerBound(): void
    {
        $r = self::judge(self::summary([], [self::gap(null, 30, [], 4)]));

        self::assertNull($r->query->lowerBound);
        self::assertNull($r->memory->lowerBound);
    }

    public function testAGapOnOneAxisLeavesTheOtherAxisKnown(): void
    {
        $r = self::judge(self::summary([], [self::gap([Axis::Query])]));

        self::assertSame(['unknown', 0, null, null], self::shape($r->query));
        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->memory));
        self::assertSame('high', $r->confidence?->value);
    }

    public function testADegreeOneEventWithAGapIsUnknownWithALowerBoundOfLinear(): void
    {
        $event = $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders')], 'table' => 'customers']);

        $r = self::judge(self::summary([$event], [self::gap([Axis::Query])]));

        self::assertSame(['unknown', 1, null, 'O(N)'], self::shape($r->query));
        self::assertSame(1, $r->eventsFound);
    }

    public function testADegreeOneEventWithoutAGapIsLinearAndHigh(): void
    {
        $event = $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders')], 'table' => 'customers']);

        $r = self::judge(self::summary([$event]));

        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->query));
        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->memory));
        self::assertSame('app/Http/Controllers/OrderController.php:40', $r->query->dominantSite?->fingerprint());
    }

    public function testTwoLoopsAreNestedAndAThirdKeepsTheLabelWithTheDegree(): void
    {
        $two = $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders'), self::frame('items', 'orders')], 'table' => 'products']);
        $three = $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders'), self::frame('items', 'orders'), self::frame('parts', 'items')], 'table' => 'x']);

        self::assertSame(['O(N*M)', 2, 'high', null], self::shape(self::judge(self::summary([$two]))->query));
        self::assertSame(['O(N*M)', 3, 'high', null], self::shape(self::judge(self::summary([$three]))->query));
    }

    public function testTheLargestDegreeAmongEventsWins(): void
    {
        $small = $this->event(['frames' => [self::frame('orders')]]);
        $large = $this->event(['frames' => [self::frame('orders'), self::frame('items', 'orders')]]);

        self::assertSame(['O(N*M)', 2, 'high', null], self::shape(self::judge(self::summary([$small, $large]))->query));
    }

    public function testAStreamedLoopCountsForQueriesButNotForMemory(): void
    {
        $event = $this->event([
            'kind' => EventKind::LazyLoad,
            'frames' => [self::frame('orders', null, true)],
            'memory' => self::memory(0, 'customers', MemoryCause::LazyToMany),
        ]);

        $r = self::judge(self::summary([$event]));

        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->query));
        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->memory));
    }

    public function testAnOwnMemoryDegreeAddsToTheNonStreamedFrames(): void
    {
        $event = $this->event([
            'kind' => EventKind::LazyLoad,
            'frames' => [self::frame('orders')],
            'memory' => self::memory(1, 'items', MemoryCause::LazyToMany, null, 'orders'),
        ]);

        self::assertSame(['O(N*M)', 2, 'high', null], self::shape(self::judge(self::summary([$event]))->memory));
    }

    public function testRetainFromCountsEveryFrameFromThatLevelEvenAStreamedOne(): void
    {
        $kept = $this->event([
            'kind' => EventKind::Accumulate,
            'query' => null,
            'frames' => [self::frame('orders', null, true)],
            'memory' => self::memory(0, 'orders', MemoryCause::Accumulate, 0),
        ]);
        $local = $this->event([
            'kind' => EventKind::Accumulate,
            'query' => null,
            'frames' => [self::frame('orders', null, true)],
            'memory' => self::memory(0, 'orders', MemoryCause::Accumulate, 1),
        ]);

        self::assertSame(['O(N)', 1, 'high', null], self::shape(self::judge(self::summary([$kept]))->memory));
        self::assertSame(['O(1)', 0, 'high', null], self::shape(self::judge(self::summary([$local]))->memory));
    }

    public function testAnUnboundedQueryOfItsOwnIsLinearEvenWithoutALoop(): void
    {
        $chunk = $this->event(['query' => self::query(true, 'orders', 1, false, 100)]);

        $r = self::judge(self::summary([$chunk]));

        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->query));
        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->memory));
    }

    public function testAnEventWithNeitherPartCostsNothingButIsCounted(): void
    {
        $r = self::judge(self::summary([$this->event(['query' => null, 'frames' => [self::frame('orders')]])]));

        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->query));
        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->memory));
        self::assertSame(1, $r->eventsFound);
    }

    public function testAnAssumptionLowersConfidenceOnlyOnTheAxesItAffects(): void
    {
        $event = $this->event(['frames' => [self::frame('orders')], 'memory' => self::memory(1, 'orders')]);

        $r = self::judge(self::summary([$event], [], [self::assumption([Axis::Memory])]));

        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->query));
        self::assertSame(['O(N)', 1, 'medium', null], self::shape($r->memory));
        self::assertSame('medium', $r->confidence?->value);
    }

    public function testAnAssumptionOnAnEmptyAxisStillLowersIt(): void
    {
        $r = self::judge(self::summary([], [], [self::assumption([Axis::Query, Axis::Memory], AssumptionCode::DRIVER_ASSUMED)]));

        self::assertSame(['O(1)', 0, 'medium', null], self::shape($r->query));
        self::assertSame('medium', $r->confidence?->value);
    }

    public function testAGapBeatsAnAssumptionOnTheSameAxis(): void
    {
        $r = self::judge(self::summary([], [self::gap([Axis::Query])], [self::assumption([Axis::Query, Axis::Memory])]));

        self::assertSame(['unknown', 0, null, null], self::shape($r->query));
        self::assertSame(['O(1)', 0, 'medium', null], self::shape($r->memory));
        self::assertSame('medium', $r->confidence?->value);
    }

    public function testRouteConfidenceIsTheLowerOfTheKnownAxes(): void
    {
        $r = self::judge(self::summary([], [], [self::assumption([Axis::Query])]));

        self::assertSame('medium', $r->confidence?->value);
        self::assertSame('high', $r->memory->confidence?->value);
    }

    public function testTruncationAndTheEventCountAreReported(): void
    {
        $r = self::judge(self::summary([$this->event(), $this->event()], [], [], [], true));

        self::assertTrue($r->truncated);
        self::assertSame(2, $r->eventsFound);
    }
}
