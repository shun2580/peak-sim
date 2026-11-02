<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data;

use InvalidArgumentException;
use PeakSim\Runtime\Data\DatabaseSchema;
use PeakSim\Runtime\Data\ForeignKey;
use PeakSim\Runtime\Data\MorphLink;
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

    // -- N = 0: the n table is empty (the response of an empty database is the reference for suspect_empty) --

    public function testNZeroEmptiesTheNTableAndKeepsTheBaseElsewhere(): void
    {
        $plan = ScalePlan::targeted(['orders'], null, null, 0, 0, 5);

        $this->assertSame(0, $plan->rowsFor('orders'));
        $this->assertSame(5, $plan->rowsFor('customers'));
        $this->assertSame(['orders' => 0], $plan->rows());
        $this->assertSame('targeted:orders', $plan->scope());
    }

    public function testNZeroGivesTheMTableNoRowsEither(): void
    {
        $plan = ScalePlan::targeted(['orders'], 'items', 'orders', 0, 3, 5);

        $this->assertSame(0, $plan->rowsFor('items'));
    }

    public function testResolveEmptiesTablesThatNeedTheEmptyTable(): void
    {
        $rows = ScalePlan::targeted(['orders'], null, null, 0, 0, 5)->resolve(Schemas::shop());

        // items.order_id is NOT NULL: an item cannot exist without an order
        $this->assertSame(['customers' => 5, 'items' => 0, 'orders' => 0, 'product_stats' => 5, 'products' => 5], $rows);
    }

    public function testEmptinessSpreadsThroughAChain(): void
    {
        $rows = ScalePlan::targeted(['customers'], null, null, 0, 0, 5)->resolve(Schemas::shop());

        $this->assertSame(0, $rows['customers']);
        $this->assertSame(0, $rows['orders']);
        $this->assertSame(0, $rows['items']);
        $this->assertSame(5, $rows['products']);
        $this->assertSame(5, $rows['product_stats']);
    }

    public function testATableWithANullableKeyToAnEmptyTableKeepsItsRows(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('teams', [Schemas::id()], ['id']),
            new TableSchema('users', [Schemas::id(), Schemas::fk('team_id', true)], ['id'], [], [new ForeignKey('users_team', ['team_id'], 'teams', ['id'])]),
        ]);

        $this->assertSame(['teams' => 0, 'users' => 5], ScalePlan::targeted(['teams'], null, null, 0, 0, 5)->resolve($schema));
    }

    public function testAnEmptyTableIsNeverRaisedForAUniqueKey(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('products', [Schemas::id()], ['id']),
            new TableSchema('stats', [Schemas::id(), Schemas::fk('product_id', true)], ['id'], [['product_id']], [new ForeignKey('stats_product', ['product_id'], 'products', ['id'])]),
        ]);

        $this->assertSame(['products' => 0, 'stats' => 5], ScalePlan::targeted(['products'], null, null, 0, 0, 5)->resolve($schema));
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

    private static function linkSchema(): DatabaseSchema
    {
        return new DatabaseSchema([
            new TableSchema('accounts', [Schemas::id()], ['id']),
            new TableSchema('piggies', [Schemas::id()], ['id']),
            new TableSchema('account_piggy', [Schemas::fk('account_id'), Schemas::fk('piggy_id')], [], [['account_id', 'piggy_id']], [
                new ForeignKey('ap_account', ['account_id'], 'accounts', ['id']),
                new ForeignKey('ap_piggy', ['piggy_id'], 'piggies', ['id']),
            ]),
        ]);
    }

    public function testALinkTableBetweenTwoTablesGrowsWithItsLargerParent(): void
    {
        // The link table is not named by the plan; it holds one link per row of the larger parent, so that
        // growing a parent grows what is reached through the link.
        $rows = ScalePlan::targeted(['accounts'], null, null, 30, 0, 5)->resolve(self::linkSchema());

        $this->assertSame(30, $rows['accounts']);
        $this->assertSame(5, $rows['piggies']);
        $this->assertSame(30, $rows['account_piggy']);
    }

    public function testALinkTableNeverHoldsFewerRowsThanTheBase(): void
    {
        $rows = ScalePlan::targeted(['accounts'], null, null, 3, 0, 5)->resolve(self::linkSchema());

        $this->assertSame(5, $rows['account_piggy']);
    }

    public function testALinkTableTheRouteNamesKeepsItsOwnCount(): void
    {
        $rows = ScalePlan::targeted(['account_piggy'], null, null, 7, 0, 5)->resolve(self::linkSchema());

        $this->assertSame(7, $rows['account_piggy']);
    }

    public function testALinkTableFollowsAnEmptyParent(): void
    {
        $rows = ScalePlan::targeted(['accounts'], null, null, 0, 0, 5)->resolve(self::linkSchema());

        $this->assertSame(0, $rows['account_piggy']);
    }

    public function testALinkTableOfALinkTableGrowsToo(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('a', [Schemas::id()], ['id']),
            new TableSchema('b', [Schemas::id()], ['id']),
            new TableSchema('c', [Schemas::id()], ['id']),
            new TableSchema('ab', [Schemas::fk('a_id'), Schemas::fk('b_id')], [], [['a_id', 'b_id']], [
                new ForeignKey('ab_a', ['a_id'], 'a', ['id']),
                new ForeignKey('ab_b', ['b_id'], 'b', ['id']),
            ]),
            new TableSchema('abc', [Schemas::fk('ab_id'), Schemas::fk('c_id')], [], [['ab_id', 'c_id']], [
                new ForeignKey('abc_ab', ['ab_id'], 'ab', ['id']),
                new ForeignKey('abc_c', ['c_id'], 'c', ['id']),
            ]),
        ]);
        // ab has no id column in this sketch; the reference is by name only, the plan reads structure.
        $rows = ScalePlan::targeted(['a'], null, null, 40, 0, 5)->resolve($schema);

        $this->assertSame(40, $rows['ab']);
        $this->assertSame(40, $rows['abc']);
    }

    public function testAChildTableOfAMorphGrowsWithItsLargestParent(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('bills', [Schemas::id()], ['id']),
            new TableSchema('notes', [Schemas::id()], ['id']),
            new TableSchema('attachments', [Schemas::id(), Schemas::column('attachable_id', 'bigint'), Schemas::varchar('attachable_type')], ['id']),
        ]);
        $morphs = [
            new MorphLink('attachments', 'attachable_id', 'attachable_type', 'App\\Bill', 'bills', 'id'),
            new MorphLink('attachments', 'attachable_id', 'attachable_type', 'App\\Note', 'notes', 'id'),
        ];

        $rows = ScalePlan::targeted(['bills'], null, null, 40, 0, 5)->resolve($schema, $morphs);

        $this->assertSame(40, $rows['attachments']);
        $this->assertSame(5, $rows['notes']);
    }

    public function testAMorphChildTheRouteNamesKeepsItsOwnCountAndAnEmptyParentEmptiesNothingElse(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('bills', [Schemas::id()], ['id']),
            new TableSchema('attachments', [Schemas::id(), Schemas::column('attachable_id', 'bigint'), Schemas::varchar('attachable_type')], ['id']),
        ]);
        $morphs = [new MorphLink('attachments', 'attachable_id', 'attachable_type', 'App\\Bill', 'bills', 'id')];

        $this->assertSame(9, ScalePlan::targeted(['attachments'], null, null, 9, 0, 5)->resolve($schema, $morphs)['attachments']);
        // no key ties the child to the parent in the database, so an empty parent does not remove the child's rows
        $this->assertSame(5, ScalePlan::targeted(['bills'], null, null, 0, 0, 5)->resolve($schema, $morphs)['attachments']);
    }

    public function testAGlobalPlanIsNotChangedByTheLinkRule(): void
    {
        $rows = ScalePlan::global(50, 5)->resolve(self::linkSchema());

        $this->assertSame(['account_piggy' => 50, 'accounts' => 50, 'piggies' => 50], $rows);
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
            'n below zero' => [fn () => ScalePlan::targeted(['a'], null, null, -1, 0, 5)],
            'base below one' => [fn () => ScalePlan::targeted(['a'], null, null, 10, 0, 0)],
            'm table without parent' => [fn () => ScalePlan::targeted(['a'], 'b', null, 10, 3, 5)],
            'm below one' => [fn () => ScalePlan::targeted(['a'], 'b', 'a', 10, 0, 5)],
            'm table is an n table' => [fn () => ScalePlan::targeted(['a', 'b'], 'b', 'a', 10, 3, 5)],
            'global n below one' => [fn () => ScalePlan::global(0, 5)],
            'global base below one' => [fn () => ScalePlan::global(10, 0)],
        ];
    }
}
