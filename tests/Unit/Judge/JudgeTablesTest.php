<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Judge;

use PeakSim\Judge\Judge;
use PeakSim\Model\EventKind;
use PeakSim\Model\MemoryCause;

/** Row (d) m_table and row (i): which event names N and M, and how ties are settled (design 1.8). */
final class JudgeTablesTest extends JudgeCase
{
    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private static function tables(\PeakSim\Model\RouteResult $r): array
    {
        return [$r->nTable, $r->mTable, $r->mPer];
    }

    public function testNestedLoopsNameBothTablesAndWhatTheInnerOneIsPer(): void
    {
        $event = $this->event([
            'kind' => EventKind::LazyLoad,
            'frames' => [self::frame('orders'), self::frame('items', 'orders')],
            'table' => 'products',
        ]);

        self::assertSame(['orders', 'items', 'orders'], self::tables(self::judge(self::summary([$event]))));
    }

    public function testAnOwnMemoryLayerIsTheNextLevelAfterTheFrames(): void
    {
        $event = $this->event([
            'kind' => EventKind::LazyLoad,
            'frames' => [self::frame('orders')],
            'table' => 'items',
            'memory' => self::memory(1, 'items', MemoryCause::LazyToMany, null, 'orders'),
        ]);

        self::assertSame(['orders', 'items', 'orders'], self::tables(self::judge(self::summary([$event]))));
    }

    public function testASingleLevelHasNoM(): void
    {
        $event = $this->event(['frames' => [self::frame('orders')], 'table' => 'customers']);

        self::assertSame(['orders', null, null], self::tables(self::judge(self::summary([$event]))));
    }

    public function testAnOwnUnboundedQueryNamesItsOwnOrigin(): void
    {
        self::assertSame(['orders', null, null], self::tables(self::judge(self::summary([
            $this->event(['query' => self::query(true, 'orders', 1, false, 100)]),
        ]))));
    }

    public function testWhenNothingGrowsTheTableTheEventReadsIsN(): void
    {
        // /ok/paginate: O(1) on both axes, and still about the orders table
        self::assertSame(['orders', null, null], self::tables(self::judge(self::summary([
            $this->event(['query' => self::query(false, null, 2)]),
        ]))));
    }

    public function testNoEventsAndNoTableMeansNoN(): void
    {
        self::assertSame([null, null, null], self::tables(self::judge(self::summary())));
        self::assertSame([null, null, null], self::tables(self::judge(self::summary([$this->event(['table' => null])]))));
    }

    public function testTheHighestDegreeAcrossBothAxesDecides(): void
    {
        // /ok/bulk: the queries are constant, the memory grows with customers
        $select = $this->event(['table' => 'customers', 'memory' => self::memory(1, 'customers')]);
        $insert = $this->event(['kind' => EventKind::Payload, 'table' => 'order_snapshots', 'memory' => self::memory(1, 'customers', MemoryCause::Payload)]);

        self::assertSame(['customers', null, null], self::tables(self::judge(self::summary([$select, $insert]))));
    }

    public function testAnEventOfLowerDegreeNeverNamesN(): void
    {
        $flat = $this->event(['table' => 'customers', 'memory' => self::memory(1, 'customers')]);
        $nested = $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders'), self::frame('items', 'orders')], 'table' => 'products']);

        self::assertSame(['orders', 'items', 'orders'], self::tables(self::judge(self::summary([$flat, $nested]))));
    }

    public function testOnATieTheQueryAxisIsPreferred(): void
    {
        // both axes reach degree 1, from different tables: the query axis names N
        $memory = $this->event(['query' => null, 'table' => 'customers', 'memory' => self::memory(1, 'customers')]);
        $query = $this->event(['kind' => EventKind::LazyLoad, 'frames' => [self::frame('orders')], 'table' => 'items']);

        self::assertSame('orders', self::judge(self::summary([$memory, $query]))->nTable);
    }

    public function testOnAFurtherTieTheRowSetQueryBeatsAnEagerOrLazyLoad(): void
    {
        // /hidden/model-with and /ok/eager: the select of the rows, then the eager load of the relation
        $eager = $this->event(['kind' => EventKind::EagerLoad, 'table' => 'customers', 'query' => self::query(true, 'customers')]);
        $select = $this->event(['kind' => EventKind::Select, 'table' => 'orders', 'query' => self::query(true, 'orders')]);

        self::assertSame('orders', self::judge(self::summary([$eager, $select]))->nTable);
    }

    public function testOnTheLastTieTheSmallerSeqWins(): void
    {
        $second = $this->event(['table' => 'products', 'memory' => self::memory(1, 'products'), 'seq' => 8]);
        $first = $this->event(['table' => 'customers', 'memory' => self::memory(1, 'customers'), 'seq' => 3]);

        self::assertSame('customers', self::judge(self::summary([$second, $first]))->nTable);
    }

    public function testTheModelWithShapeNamesTheModelTableNotTheEagerRelation(): void
    {
        // /hidden/model-with: orders are loaded (memory O(N)); customers come with them in one more query
        $select = $this->event(['kind' => EventKind::Select, 'table' => 'orders', 'memory' => self::memory(1, 'orders')]);
        $eager = $this->event(['kind' => EventKind::EagerLoad, 'table' => 'customers']);

        $r = self::judge(self::summary([$select, $eager]));

        self::assertSame(['orders', null, null], self::tables($r));
        self::assertSame(['O(1)', 0, 'high', null], self::shape($r->query));
        self::assertSame(['O(N)', 1, 'high', null], self::shape($r->memory));
    }

    public function testTablesThatTieAreOfferedAsAlternatives(): void
    {
        $a = $this->event(['table' => 'customers', 'memory' => self::memory(1, 'customers'), 'seq' => 3]);
        $b = $this->event(['table' => 'products', 'memory' => self::memory(1, 'products'), 'seq' => 8]);
        $c = $this->event(['table' => 'products', 'memory' => self::memory(1, 'products'), 'seq' => 9]);
        $summary = self::summary([$c, $b, $a]);

        self::assertSame('customers', self::judge($summary)->nTable);
        self::assertSame(['products'], (new Judge())->tableAlternatives($summary));
    }

    public function testNoAlternativesWhenTheTiedEventsShareTheTable(): void
    {
        $a = $this->event(['table' => 'orders', 'memory' => self::memory(1, 'orders')]);
        $b = $this->event(['table' => 'orders', 'memory' => self::memory(1, 'orders')]);

        self::assertSame([], (new Judge())->tableAlternatives(self::summary([$a, $b])));
    }

    public function testLowerRankedEventsAreNotAlternatives(): void
    {
        $select = $this->event(['kind' => EventKind::Select, 'table' => 'orders', 'memory' => self::memory(1, 'orders')]);
        $lazy = $this->event(['kind' => EventKind::LazyLoad, 'table' => 'customers', 'memory' => self::memory(1, 'customers', MemoryCause::LazyToMany)]);

        self::assertSame([], (new Judge())->tableAlternatives(self::summary([$select, $lazy])));
    }

    public function testAnUnnamedTableIsNeverReportedAsN(): void
    {
        $event = $this->event(['table' => null, 'frames' => [self::frame('orders')]]);
        $unnamed = $this->event(['table' => null, 'query' => self::query(true), 'memory' => self::memory(1, null)]);

        self::assertSame(['orders', null, null], self::tables(self::judge(self::summary([$event]))));
        self::assertNull(self::judge(self::summary([$unnamed]))->nTable);
    }
}
