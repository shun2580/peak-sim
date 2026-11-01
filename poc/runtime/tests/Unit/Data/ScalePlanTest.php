<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data;

use InvalidArgumentException;
use PeakSim\Runtime\Data\DatabaseSchema;
use PeakSim\Runtime\Data\ForeignKey;
use PeakSim\Runtime\Data\ScalePlan;
use PeakSim\Runtime\Data\TableSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScalePlanTest extends TestCase
{
    public function testTargetedGivesTheNTableNRowsAndEveryoneElseTheBase(): void
    {
        $plan = ScalePlan::targeted(['orders'], null, null, 100, 0, 5);

        $this->assertSame(100, $plan->rowsFor('orders'));
        $this->assertSame(5, $plan->rowsFor('customers'));
        $this->assertSame(['orders' => 100], $plan->rows());
        $this->assertSame('targeted:orders', $plan->scope());
    }

    public function testTargetedWithSeveralNTablesRaisesAllOfThem(): void
    {
        $plan = ScalePlan::targeted(['orders', 'items'], null, null, 30, 0, 5);

        $this->assertSame(30, $plan->rowsFor('orders'));
        $this->assertSame(30, $plan->rowsFor('items'));
        $this->assertSame('targeted:items,orders', $plan->scope());
    }

    public function testTheMTableHasMRowsPerRowOfItsParent(): void
    {
        $plan = ScalePlan::targeted(['orders'], 'items', 'orders', 20, 30, 5);

        $this->assertSame(20, $plan->rowsFor('orders'));
        $this->assertSame(600, $plan->rowsFor('items'));
    }

    public function testTheMTableUsesTheBaseWhenItsParentIsNotScaled(): void
    {
        $plan = ScalePlan::targeted(['customers'], 'items', 'orders', 100, 3, 5);

        $this->assertSame(15, $plan->rowsFor('items'));
    }

    public function testGlobalScalesEveryTable(): void
    {
        $plan = ScalePlan::global(1000, 5);

        $this->assertSame(1000, $plan->rowsFor('anything'));
        $this->assertSame('global', $plan->scope());
        $this->assertSame([], $plan->rows());
    }

    public function testGlobalKeepsTheFixedTablesAtTheBase(): void
    {
        $plan = ScalePlan::global(1000, 5, ['roles', 'permissions']);

        $this->assertSame(5, $plan->rowsFor('roles'));
        $this->assertSame(5, $plan->rowsFor('permissions'));
        $this->assertSame(1000, $plan->rowsFor('songs'));
        $this->assertSame(['permissions' => 5, 'roles' => 5], $plan->rows());
    }

    public function testResolveListsEveryTableOfTheSchema(): void
    {
        $rows = ScalePlan::targeted(['orders'], 'items', 'orders', 20, 3, 5)->resolve(Schemas::shop());

        // the map follows the schema's table order, which is by name
        $this->assertSame(['customers' => 5, 'items' => 60, 'orders' => 20, 'product_stats' => 5, 'products' => 5], $rows);
    }

    public function testResolveRaisesTheParentOfAOneToOneUniqueForeignKey(): void
    {
        // product_stats.product_id is unique: 50 stats need 50 different products.
        $rows = ScalePlan::targeted(['product_stats'], null, null, 50, 0, 5)->resolve(Schemas::shop());

        $this->assertSame(50, $rows['product_stats']);
        $this->assertSame(50, $rows['products']);
        $this->assertSame(5, $rows['customers']);
    }

    public function testResolveRaisesTheParentsOfAPivotSoThatTheirProductCoversTheRows(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('users', [Schemas::id()], ['id']),
            new TableSchema('songs', [Schemas::id()], ['id']),
            new TableSchema('likes', [Schemas::id(), Schemas::fk('user_id'), Schemas::fk('song_id')], ['id'], [['user_id', 'song_id']], [
                new ForeignKey('likes_user', ['user_id'], 'users', ['id']),
                new ForeignKey('likes_song', ['song_id'], 'songs', ['id']),
            ]),
        ]);

        $rows = ScalePlan::targeted(['likes'], null, null, 100, 0, 5)->resolve($schema);

        $this->assertSame(100, $rows['likes']);
        $this->assertGreaterThanOrEqual(100, $rows['users'] * $rows['songs']);
        $this->assertSame(10, $rows['users']);
        $this->assertSame(10, $rows['songs']);
    }

    public function testResolveRaisesGrandparentsThroughAChainOfUniqueKeys(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('a', [Schemas::id()], ['id']),
            new TableSchema('b', [Schemas::id(), Schemas::fk('a_id')], ['id'], [['a_id']], [new ForeignKey('b_a', ['a_id'], 'a', ['id'])]),
            new TableSchema('c', [Schemas::id(), Schemas::fk('b_id')], ['id'], [['b_id']], [new ForeignKey('c_b', ['b_id'], 'b', ['id'])]),
        ]);

        $rows = ScalePlan::targeted(['c'], null, null, 40, 0, 5)->resolve($schema);

        $this->assertSame(['a' => 40, 'b' => 40, 'c' => 40], $rows);
    }

    public function testAUniqueForeignKeyToTheTableItselfDoesNotLoop(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('nodes', [Schemas::id(), Schemas::fk('next_id', true)], ['id'], [['next_id']], [new ForeignKey('nodes_next', ['next_id'], 'nodes', ['id'])]),
        ]);

        $this->assertSame(['nodes' => 7], ScalePlan::targeted(['nodes'], null, null, 7, 0, 5)->resolve($schema));
    }

    public function testResolveDoesNotLowerAnyTable(): void
    {
        $rows = ScalePlan::global(1000, 5)->resolve(Schemas::shop());

        $this->assertSame(array_fill_keys(array_keys($rows), 1000), $rows);
    }

    public function testRowsAreDeterministic(): void
    {
        $a = ScalePlan::targeted(['orders'], 'items', 'orders', 20, 3, 5)->resolve(Schemas::shop());
        $b = ScalePlan::targeted(['orders'], 'items', 'orders', 20, 3, 5)->resolve(Schemas::shop());

        $this->assertSame($a, $b);
    }

    #[DataProvider('badArguments')]
    public function testBadArgumentsAreRefused(callable $make): void
    {
        $this->expectException(InvalidArgumentException::class);
        $make();
    }

    /** @return array<string, array{0: callable}> */
    public static function badArguments(): array
    {
        return [
            'no n table' => [fn () => ScalePlan::targeted([], null, null, 10, 0, 5)],
            'n below one' => [fn () => ScalePlan::targeted(['a'], null, null, 0, 0, 5)],
            'base below one' => [fn () => ScalePlan::targeted(['a'], null, null, 10, 0, 0)],
            'm table without parent' => [fn () => ScalePlan::targeted(['a'], 'b', null, 10, 3, 5)],
            'm below one' => [fn () => ScalePlan::targeted(['a'], 'b', 'a', 10, 0, 5)],
            'm table is an n table' => [fn () => ScalePlan::targeted(['a', 'b'], 'b', 'a', 10, 3, 5)],
            'global n below one' => [fn () => ScalePlan::global(0, 5)],
            'global base below one' => [fn () => ScalePlan::global(10, 0)],
        ];
    }
}
