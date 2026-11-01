<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data;

use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Data\DatabaseSchema;
use PeakSim\Runtime\Data\Generator;
use PeakSim\Runtime\Data\ForeignKey;
use PeakSim\Runtime\Data\Reason;
use PeakSim\Runtime\Data\ScalePlan;
use PeakSim\Runtime\Data\TableOutcome;
use PeakSim\Runtime\Data\TableSchema;
use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase
{
    private function generate(DatabaseSchema $schema, ScalePlan $plan, ?RecordingSink $sink = null, array $profile = []): array
    {
        $sink ??= new RecordingSink();
        $report = (new Generator())->generateInto($schema, $sink, $plan, $profile);

        return [$report, $sink];
    }

    public function testAnOrdinarySchemaIsGeneratedParentsFirstWithThePlannedRows(): void
    {
        [$report, $sink] = $this->generate(Schemas::shop(), ScalePlan::targeted(['orders'], null, null, 12, 0, 5));

        $this->assertSame(5, $report->generatedCount());
        $this->assertSame(0, $report->failedCount());
        $this->assertSame(1.0, $report->successRate());
        $this->assertSame(12, $report->outcome('orders')->planned);
        $this->assertSame(12, $report->outcome('orders')->actual);
        $this->assertSame(5, $report->outcome('customers')->actual);

        $tables = $sink->insertedTables();
        $this->assertLessThan(array_search('orders', $tables, true), array_search('customers', $tables, true));
        $this->assertLessThan(array_search('items', $tables, true), array_search('orders', $tables, true));
        $this->assertLessThan(array_search('items', $tables, true), array_search('products', $tables, true));
        $this->assertLessThan(array_search('product_stats', $tables, true), array_search('products', $tables, true));
    }

    public function testRowsHaveTheRulesOfTheColumnTable(): void
    {
        [, $sink] = $this->generate(Schemas::shop(), ScalePlan::targeted(['orders'], null, null, 12, 0, 5));

        $first = $sink->assoc('orders')[0];
        $this->assertSame([
            'customer_id' => 1,
            'status' => 'orders_status_1',
            'total' => '1.50',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $first);
        // the auto-increment id is left to the database
        $this->assertArrayNotHasKey('id', $first);
        // customers are handed out round robin
        $this->assertSame([1, 2, 3, 4, 5, 1, 2, 3, 4, 5, 1, 2], array_column($sink->assoc('orders'), 'customer_id'));
    }

    public function testColumnsWithADefaultAreLeftOut(): void
    {
        [, $sink] = $this->generate(Schemas::shop(), ScalePlan::global(6, 6));

        $this->assertArrayNotHasKey('units_sold', $sink->assoc('product_stats')[0]);
    }

    public function testTheMAxisGivesEachParentExactlyMChildren(): void
    {
        [$report, $sink] = $this->generate(Schemas::shop(), ScalePlan::targeted(['orders'], 'items', 'orders', 20, 3, 5));

        $this->assertSame(60, $report->outcome('items')->actual);
        $perOrder = array_count_values(array_column($sink->assoc('items'), 'order_id'));
        $this->assertCount(20, $perOrder);
        $this->assertSame([3], array_values(array_unique($perOrder)));
    }

    public function testAOneToOneUniqueForeignKeyGetsDistinctParents(): void
    {
        [$report, $sink] = $this->generate(Schemas::shop(), ScalePlan::targeted(['product_stats'], null, null, 40, 0, 5));

        $this->assertSame(40, $report->outcome('products')->planned);
        $ids = array_column($sink->assoc('product_stats'), 'product_id');
        $this->assertCount(40, array_unique($ids));
    }

    public function testAPivotGetsDistinctPairsOfParents(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('users', [Schemas::id()], ['id']),
            new TableSchema('songs', [Schemas::id()], ['id']),
            new TableSchema('likes', [Schemas::id(), Schemas::fk('user_id'), Schemas::fk('song_id')], ['id'], [['user_id', 'song_id']], [
                new ForeignKey('likes_user', ['user_id'], 'users', ['id']),
                new ForeignKey('likes_song', ['song_id'], 'songs', ['id']),
            ]),
        ]);
        [$report, $sink] = $this->generate($schema, ScalePlan::targeted(['likes'], null, null, 100, 0, 5));

        $this->assertSame(1.0, $report->successRate());
        $pairs = array_map(fn (array $r) => $r['user_id'] . '-' . $r['song_id'], $sink->assoc('likes'));
        $this->assertCount(100, array_unique($pairs));
        $this->assertLessThanOrEqual(10, max(array_column($sink->assoc('likes'), 'user_id')));
        $this->assertLessThanOrEqual(10, max(array_column($sink->assoc('likes'), 'song_id')));
    }

    public function testAForeignKeyToANonKeyColumnGetsTheParentsValue(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('countries', [Schemas::id(), Schemas::varchar('code', 10)], ['id'], [['code']]),
            new TableSchema('cities', [Schemas::id(), Schemas::varchar('country_code', 10)], ['id'], [], [
                new ForeignKey('cities_country', ['country_code'], 'countries', ['code']),
            ]),
        ]);
        [, $sink] = $this->generate($schema, ScalePlan::targeted(['cities'], null, null, 8, 0, 3));

        $codes = array_column($sink->assoc('countries'), 'code');
        foreach (array_column($sink->assoc('cities'), 'country_code') as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function testRowsAreInsertedInBatches(): void
    {
        $schema = new DatabaseSchema([new TableSchema('logs', [Schemas::id(), Schemas::varchar('msg', 50)], ['id'])]);
        [, $sink] = $this->generate($schema, ScalePlan::global(2500, 5));

        $this->assertSame(['insert logs x1000', 'insert logs x1000', 'insert logs x500'], array_values(array_filter($sink->log, fn ($l) => str_starts_with($l, 'insert'))));
    }

    public function testEachTableIsOneTransaction(): void
    {
        [, $sink] = $this->generate(Schemas::shop(), ScalePlan::global(6, 6));

        $this->assertSame(5, count(array_filter($sink->log, fn ($l) => $l === 'begin')));
        $this->assertSame(5, count(array_filter($sink->log, fn ($l) => $l === 'commit')));
        $this->assertSame(0, $sink->rollBacks);
    }

    public function testGenerationIsDeterministic(): void
    {
        [, $a] = $this->generate(Schemas::shop(), ScalePlan::targeted(['orders'], 'items', 'orders', 20, 3, 5));
        [, $b] = $this->generate(Schemas::shop(), ScalePlan::targeted(['orders'], 'items', 'orders', 20, 3, 5));

        $this->assertSame($a->log, $b->log);
        $this->assertSame($a->batches, $b->batches);
    }

    // -- safety: only a measurement database --

    public function testItRefusesAnyDatabaseThatIsNotAMeasurementDatabase(): void
    {
        $sink = new RecordingSink('app');

        try {
            (new Generator())->generateInto(Schemas::shop(), $sink, ScalePlan::global(6, 6), []);
            $this->fail('expected a refusal');
        } catch (MeasureRefused) {
            $this->assertSame([], $sink->log);
        }
    }

    public function testItRefusesWhenNoDatabaseIsSelected(): void
    {
        $this->expectException(MeasureRefused::class);
        (new Generator())->generateInto(Schemas::shop(), new RecordingSink(null), ScalePlan::global(6, 6), []);
    }

    // -- failures are recorded with a reason, never hidden --

    public function testAFailingInsertFailsOnlyThatTableAndItsDescendants(): void
    {
        $sink = new RecordingSink();
        $sink->failOn['orders'] = [Reason::CHECK_CONSTRAINT, 'Check constraint orders_chk_1 is violated.'];
        [$report] = $this->generate(Schemas::shop(), ScalePlan::global(6, 6), $sink);

        $this->assertSame(TableOutcome::FAILED, $report->outcome('orders')->status);
        $this->assertSame(Reason::CHECK_CONSTRAINT, $report->outcome('orders')->reason);
        $this->assertStringContainsString('orders_chk_1', $report->outcome('orders')->detail);
        $this->assertSame(Reason::PARENT_NOT_GENERATED, $report->outcome('items')->reason);
        $this->assertStringContainsString('orders', $report->outcome('items')->detail);
        $this->assertSame(TableOutcome::GENERATED, $report->outcome('customers')->status);
        $this->assertSame(TableOutcome::GENERATED, $report->outcome('product_stats')->status);
        $this->assertSame(1, $sink->rollBacks);
        $this->assertEqualsWithDelta(3 / 5, $report->successRate(), 0.0001);
    }

    public function testAnUnsupportedRequiredColumnFailsTheTableBeforeAnyInsert(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('places', [Schemas::id(), Schemas::column('location', 'point')], ['id']),
            new TableSchema('visits', [Schemas::id(), Schemas::fk('place_id')], ['id'], [], [new ForeignKey('visits_place', ['place_id'], 'places', ['id'])]),
            new TableSchema('notes', [Schemas::id(), Schemas::fk('place_id', true)], ['id'], [], [new ForeignKey('notes_place', ['place_id'], 'places', ['id'])]),
        ]);
        [$report, $sink] = $this->generate($schema, ScalePlan::global(6, 6));

        $this->assertSame(Reason::UNSUPPORTED_TYPE, $report->outcome('places')->reason);
        $this->assertStringContainsString('location', $report->outcome('places')->detail);
        $this->assertSame(Reason::PARENT_NOT_GENERATED, $report->outcome('visits')->reason);
        // a nullable foreign key to a table that could not be generated stays NULL, and says so
        $this->assertSame(TableOutcome::GENERATED, $report->outcome('notes')->status);
        $this->assertSame([null], array_values(array_unique(array_column($sink->assoc('notes'), 'place_id'))));
        $this->assertNotEmpty($report->outcome('notes')->notes);
        $this->assertStringContainsString('place_id', $report->outcome('notes')->notes[0]);
        $this->assertArrayNotHasKey('places', $sink->batches);
    }

    public function testAnFkCycleOfNotNullKeysIsRecordedAsSuch(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('a', [Schemas::id(), Schemas::fk('b_id')], ['id'], [], [new ForeignKey('a_b', ['b_id'], 'b', ['id'])]),
            new TableSchema('b', [Schemas::id(), Schemas::fk('a_id')], ['id'], [], [new ForeignKey('b_a', ['a_id'], 'a', ['id'])]),
            new TableSchema('c', [Schemas::id(), Schemas::fk('a_id')], ['id'], [], [new ForeignKey('c_a', ['a_id'], 'a', ['id'])]),
            new TableSchema('d', [Schemas::id()], ['id']),
        ]);
        [$report, $sink] = $this->generate($schema, ScalePlan::global(6, 6));

        $this->assertSame(Reason::FK_CYCLE, $report->outcome('a')->reason);
        $this->assertSame(Reason::FK_CYCLE, $report->outcome('b')->reason);
        $this->assertSame(Reason::PARENT_NOT_GENERATED, $report->outcome('c')->reason);
        $this->assertSame(TableOutcome::GENERATED, $report->outcome('d')->status);
        $this->assertSame(['d'], $sink->insertedTables());
        $this->assertSame([Reason::FK_CYCLE => 2, Reason::PARENT_NOT_GENERATED => 1], $report->reasonCounts());
    }

    public function testANullableSelfReferenceIsFilledInAfterTheInserts(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('categories', [Schemas::id(), Schemas::fk('parent_id', true), Schemas::varchar('name')], ['id'], [], [
                new ForeignKey('categories_parent', ['parent_id'], 'categories', ['id']),
            ]),
        ]);
        [$report, $sink] = $this->generate($schema, ScalePlan::global(10, 5));

        $this->assertSame(1.0, $report->successRate());
        $this->assertSame([null], array_values(array_unique(array_column($sink->assoc('categories'), 'parent_id'))));
        $this->assertSame(['UPDATE `categories` SET `parent_id` = `id` DIV 2 WHERE `id` > 1'], $sink->executed);
        // the update comes after the last insert
        $this->assertSame('execute UPDATE `categories` SET `parent_id` = `id` DIV 2 WHERE `id` > 1', $sink->log[count($sink->log) - 1]);
    }

    public function testAMutualCycleIsBrokenAtTheNullableKey(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('users', [Schemas::id(), Schemas::fk('team_id')], ['id'], [], [new ForeignKey('users_team', ['team_id'], 'teams', ['id'])]),
            new TableSchema('teams', [Schemas::id(), Schemas::fk('owner_id', true)], ['id'], [], [new ForeignKey('teams_owner', ['owner_id'], 'users', ['id'])]),
        ]);
        [$report, $sink] = $this->generate($schema, ScalePlan::global(6, 6));

        $this->assertSame(1.0, $report->successRate());
        $this->assertSame(['teams', 'users'], $sink->insertedTables());
        $this->assertSame(['UPDATE `teams` SET `owner_id` = ((`id` - 1) % 6) + 1'], $sink->executed);
    }

    public function testADeferredKeyThatCannotBeFilledStaysNullAndIsNoted(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('nodes', [
                Schemas::varchar('code', 20),
                Schemas::varchar('parent_code', 20, true),
            ], ['code'], [], [new ForeignKey('nodes_parent', ['parent_code'], 'nodes', ['code'])]),
        ]);
        [$report, $sink] = $this->generate($schema, ScalePlan::global(10, 5));

        $this->assertSame(TableOutcome::GENERATED, $report->outcome('nodes')->status);
        $this->assertSame([], $sink->executed);
        $this->assertNotEmpty($report->outcome('nodes')->notes);
    }

    public function testACountThatDoesNotMatchThePlanFailsTheTable(): void
    {
        $sink = new RecordingSink();
        $sink->countOverride['customers'] = 4;
        [$report] = $this->generate(Schemas::shop(), ScalePlan::global(6, 6), $sink);

        $this->assertSame(Reason::COUNT_MISMATCH, $report->outcome('customers')->reason);
        $this->assertSame(6, $report->outcome('customers')->planned);
        $this->assertSame(4, $report->outcome('customers')->actual);
        $this->assertSame(1, $sink->rollBacks);
    }

    public function testAnAutoIncrementThatIsNotOneToNFailsTheTable(): void
    {
        $sink = new RecordingSink();
        $sink->spanOverride['customers'] = [1, 9];
        [$report] = $this->generate(Schemas::shop(), ScalePlan::global(6, 6), $sink);

        $this->assertSame(Reason::AUTOINC_GAP, $report->outcome('customers')->reason);
    }

    // -- what is not generated --

    public function testFrameworkBookkeepingTablesAreSkipped(): void
    {
        $schema = new DatabaseSchema([
            new TableSchema('migrations', [Schemas::id(), Schemas::varchar('migration'), Schemas::column('batch', 'int', 'int', ['precision' => 10, 'scale' => 0])], ['id']),
            new TableSchema('sessions', [Schemas::varchar('id', 40)], ['id']),
            new TableSchema('songs', [Schemas::id()], ['id']),
        ]);
        [$report, $sink] = $this->generate($schema, ScalePlan::global(6, 6));

        $this->assertSame(TableOutcome::SKIPPED, $report->outcome('migrations')->status);
        $this->assertSame(Reason::FRAMEWORK_TABLE, $report->outcome('migrations')->reason);
        $this->assertSame(TableOutcome::SKIPPED, $report->outcome('sessions')->status);
        $this->assertSame(['songs'], $sink->insertedTables());
        // skipped tables are not failures
        $this->assertSame(1.0, $report->successRate());
        $this->assertSame(1, $report->generatedCount());
        $this->assertSame(2, $report->skippedCount());
    }

    public function testTheProfileCanSkipATable(): void
    {
        [$report, $sink] = $this->generate(Schemas::shop(), ScalePlan::global(6, 6), null, ['skip' => ['product_stats']]);

        $this->assertSame(Reason::PROFILE_SKIP, $report->outcome('product_stats')->reason);
        $this->assertArrayNotHasKey('product_stats', $sink->batches);
    }

    public function testTheProfileCanSetTheRowsOfATable(): void
    {
        [$report, $sink] = $this->generate(Schemas::shop(), ScalePlan::global(6, 6), null, ['rows' => ['customers' => 2]]);

        $this->assertSame(2, $report->outcome('customers')->actual);
        $this->assertSame([1, 2, 1, 2, 1, 2], array_column($sink->assoc('orders'), 'customer_id'));
    }

    public function testTheProfileCanFixAColumnValue(): void
    {
        [, $sink] = $this->generate(Schemas::shop(), ScalePlan::global(6, 6), null, ['values' => ['products' => ['name' => 'Fixed name']]]);

        $this->assertSame(['Fixed name'], array_values(array_unique(array_column($sink->assoc('products'), 'name'))));
    }

    // -- the report --

    public function testTheReportSerialisesWithoutAnyClock(): void
    {
        [$report] = $this->generate(Schemas::shop(), ScalePlan::targeted(['orders'], null, null, 12, 0, 5));
        $data = $report->toArray();

        $this->assertSame('targeted:orders', $data['scope']);
        $this->assertSame(5, $data['generated']);
        $this->assertSame(0, $data['failed']);
        $this->assertSame(1.0, $data['success_rate']);
        $this->assertSame(['table' => 'orders', 'status' => 'generated', 'planned' => 12, 'actual' => 12, 'reason' => null, 'detail' => null, 'notes' => []], $data['tables'][array_search('orders', array_column($data['tables'], 'table'), true)]);
        $this->assertJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testAnEmptySchemaIsAFullSuccess(): void
    {
        [$report] = $this->generate(new DatabaseSchema([]), ScalePlan::global(6, 6));

        $this->assertSame(1.0, $report->successRate());
        $this->assertSame(0, $report->generatedCount());
    }

    public function testLongDetailsAreCut(): void
    {
        $sink = new RecordingSink();
        $sink->failOn['customers'] = [Reason::INSERT_ERROR, str_repeat('x', 1000)];
        [$report] = $this->generate(Schemas::shop(), ScalePlan::global(6, 6), $sink);

        $this->assertSame(200, strlen($report->outcome('customers')->detail));
    }
}
