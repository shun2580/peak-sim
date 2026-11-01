<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data;

use PeakSim\Runtime\Data\DatabaseSchema;
use PeakSim\Runtime\Data\FkOrder;
use PeakSim\Runtime\Data\ForeignKey;
use PeakSim\Runtime\Data\TableSchema;
use PHPUnit\Framework\TestCase;

final class FkOrderTest extends TestCase
{
    /** @param list<array{0: string, 1: string, 2: bool}> $edges child, parent, nullable */
    private function schema(array $tables, array $edges): DatabaseSchema
    {
        $built = [];
        foreach ($tables as $name) {
            $columns = [Schemas::id()];
            $fks = [];
            foreach ($edges as [$child, $parent, $nullable]) {
                if ($child === $name) {
                    $column = $parent . '_id';
                    $columns[] = Schemas::fk($column, $nullable);
                    $fks[] = new ForeignKey($name . '_' . $column, [$column], $parent, ['id']);
                }
            }
            $built[] = new TableSchema($name, $columns, ['id'], [], $fks);
        }

        return new DatabaseSchema($built);
    }

    public function testParentsComeBeforeChildren(): void
    {
        $plan = FkOrder::sort(Schemas::shop(), ['customers', 'products', 'orders', 'items', 'product_stats']);

        $this->assertSame([], $plan->cyclic);
        $this->assertSame([], $plan->blocked);
        $this->assertSame([], $plan->deferred);
        $order = array_flip($plan->order);
        $this->assertLessThan($order['orders'], $order['customers']);
        $this->assertLessThan($order['items'], $order['orders']);
        $this->assertLessThan($order['items'], $order['products']);
        $this->assertLessThan($order['product_stats'], $order['products']);
        $this->assertCount(5, $plan->order);
    }

    public function testTheOrderIsDeterministic(): void
    {
        $tables = ['product_stats', 'items', 'orders', 'products', 'customers'];

        $this->assertSame(FkOrder::sort(Schemas::shop(), $tables)->order, FkOrder::sort(Schemas::shop(), array_reverse($tables))->order);
    }

    public function testTablesWithoutRelationsAreOrderedByName(): void
    {
        $schema = $this->schema(['c', 'a', 'b'], []);

        $this->assertSame(['a', 'b', 'c'], FkOrder::sort($schema, ['c', 'a', 'b'])->order);
    }

    public function testANullableSelfReferenceIsDeferred(): void
    {
        $schema = $this->schema(['categories'], [['categories', 'categories', true]]);
        $plan = FkOrder::sort($schema, ['categories']);

        $this->assertSame(['categories'], $plan->order);
        $this->assertCount(1, $plan->deferred);
        $this->assertSame('categories', $plan->deferred[0]['table']);
        $this->assertSame('categories_categories_id', $plan->deferred[0]['fk']->name);
        $this->assertSame([], $plan->cyclic);
    }

    public function testANotNullSelfReferenceIsAnFkCycle(): void
    {
        $schema = $this->schema(['categories'], [['categories', 'categories', false]]);
        $plan = FkOrder::sort($schema, ['categories']);

        $this->assertSame([], $plan->order);
        $this->assertSame(['categories'], $plan->cyclic);
    }

    public function testAMutualCycleIsBrokenAtTheNullableEdge(): void
    {
        $schema = $this->schema(['users', 'teams'], [['users', 'teams', false], ['teams', 'users', true]]);
        $plan = FkOrder::sort($schema, ['users', 'teams']);

        $this->assertSame(['teams', 'users'], $plan->order);
        $this->assertCount(1, $plan->deferred);
        $this->assertSame('teams', $plan->deferred[0]['table']);
        $this->assertSame([], $plan->cyclic);
    }

    public function testAMutualCycleOfNotNullKeysFailsBothTablesAndBlocksTheirChildren(): void
    {
        $schema = $this->schema(['a', 'b', 'c'], [['a', 'b', false], ['b', 'a', false], ['c', 'a', false]]);
        $plan = FkOrder::sort($schema, ['a', 'b', 'c']);

        $this->assertSame([], $plan->order);
        $this->assertSame(['a', 'b'], $plan->cyclic);
        $this->assertSame(['c'], $plan->blocked);
    }

    public function testATableOutsideTheConsideredSetIsNotAnEdge(): void
    {
        // `orders` is not part of the run (say, skipped): items does not wait for it.
        $plan = FkOrder::sort(Schemas::shop(), ['customers', 'products', 'items']);

        $this->assertSame([], $plan->cyclic);
        $this->assertCount(3, $plan->order);
    }

    public function testAThreeTableCycleIsBrokenWithOneDeferredEdge(): void
    {
        $schema = $this->schema(['a', 'b', 'c'], [['a', 'b', false], ['b', 'c', false], ['c', 'a', true]]);
        $plan = FkOrder::sort($schema, ['a', 'b', 'c']);

        $this->assertSame(['c', 'b', 'a'], $plan->order);
        $this->assertCount(1, $plan->deferred);
        $this->assertSame('c', $plan->deferred[0]['table']);
    }
}
