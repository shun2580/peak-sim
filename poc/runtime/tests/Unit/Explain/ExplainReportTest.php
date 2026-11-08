<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Explain\CaptureFile;
use PeakSim\Runtime\Explain\ExplainReport;
use PeakSim\Runtime\Explain\ExplainRun;
use PeakSim\Runtime\Explain\ExplainSender;
use PHPUnit\Framework\TestCase;

final class ExplainReportTest extends TestCase
{
    private const INDEXED = ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 'references', 'access_type' => 'range', 'key' => 'by_from', 'rows_examined_per_scan' => 40, 'filtered' => '100.00']]];

    private const FULL_SCAN = ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 'references', 'access_type' => 'ALL', 'rows_examined_per_scan' => 98000, 'filtered' => '1.00']]];

    /** @return array<string, mixed> */
    private static function capture(): array
    {
        return [
            'capture_version' => 1,
            'scales' => [100, 1000, 10000],
            'routes' => [
                ['route' => 'PUT /books/{id}', 'status' => 'captured', 'statement_types' => [
                    ['id' => 's1', 'kind' => 'delete', 'pattern' => 'delete from `references` where `from_type` = ? and `from_id` in (?)', 'representative' => ['scale' => 10000, 'sql' => 'delete from `references` where `from_type` = ? and `from_id` in (?)', 'bindings' => ['Page', 3]]],
                    ['id' => 's2', 'kind' => 'update', 'pattern' => 'update `x` set `a` = ?', 'representative' => ['scale' => 10000, 'sql' => 'update `x` set `a` = ?', 'bindings' => [1]]],
                    ['id' => 's3', 'kind' => 'select', 'pattern' => 'select * from `users` where `password` = ?', 'representative' => ['scale' => 10000, 'sql' => 'select * from `users` where `password` = ?', 'bindings' => ['[redacted]']]],
                    ['id' => 's4', 'kind' => 'begin', 'pattern' => 'begin', 'representative' => ['scale' => 10000, 'sql' => 'begin', 'bindings' => []]],
                    ['id' => 's5', 'kind' => 'select', 'pattern' => 'select 1', 'representative' => null],
                ]],
            ],
        ];
    }

    public function testTheCaptureIsReadWithItsRedactionAndItsMissingRepresentatives(): void
    {
        $read = CaptureFile::read(self::capture());

        $this->assertSame(['PUT /books/{id}'], $read['routes']);
        $this->assertCount(5, $read['statements']);
        $this->assertTrue($read['statements'][2]['bindings_redacted']);
        $this->assertFalse($read['statements'][0]['bindings_redacted']);
        $this->assertSame('no_representative_in_the_capture', $read['statements'][4]['not_explained']);
        $this->assertSame([100, 1000, 10000], $read['scales']);
    }

    public function testTheRunExplainsEveryDataStatementAndNeverSendsAKindThatCannotBeExplained(): void
    {
        $transport = new RecordingTransport();
        $read = CaptureFile::read(self::capture());

        $results = ExplainRun::run(new ExplainSender($transport), $read['statements'], ['PUT /books/{id}']);

        $this->assertCount(5, $results);
        $this->assertCount(3, $transport->sent, 'the delete, the update and the select; not the begin, not the one without a representative');
        foreach ($transport->sent as $sent) {
            $this->assertStringStartsWith('EXPLAIN FORMAT=JSON ', $sent['sql']);
        }
        $this->assertSame('not_explainable_kind: begin', $results[3]['unavailable']);
        $this->assertSame('no_representative_in_the_capture', $results[4]['unavailable']);
    }

    public function testTwoTypesWithTheSameTextAndValuesAreAskedOnce(): void
    {
        $transport = new RecordingTransport();
        $statements = CaptureFile::read(self::capture())['statements'];
        $twin = $statements[0];
        $twin['id'] = 's1b';
        $statements[] = $twin;

        ExplainRun::run(new ExplainSender($transport), $statements, ['PUT /books/{id}']);

        $this->assertCount(3, $transport->sent);
    }

    public function testARouteOfAnotherDatabaseIsLeftToThatDatabase(): void
    {
        $transport = new RecordingTransport();
        $statements = CaptureFile::read(self::capture())['statements'];

        $results = ExplainRun::run(new ExplainSender($transport), $statements, ['GET /elsewhere']);

        $this->assertSame([], $results);
        $this->assertSame([], $transport->sent);
    }

    /**
     * @param array<string, mixed>|null $small
     * @param array<string, array<string, mixed>|null> $target state => plan
     *
     * @return array<string, mixed>
     */
    private static function report(?array $small, array $target, array $unplanned = []): array
    {
        $statements = [['route' => 'R', 'id' => 's1', 'kind' => 'delete', 'pattern' => 'p', 'sql' => 'x', 'bindings' => [], 'bindings_redacted' => false, 'representative_scale' => 10000, 'not_explained' => null]];
        $groups = [];
        if ($small !== null) {
            $groups[] = ['role' => 'small', 'scale' => 10000, 'database' => 'd1', 'routes' => ['R'], 'results' => ['default' => [['route' => 'R', 'id' => 's1', 'plan' => $small, 'unavailable' => null]]]];
        }
        $results = [];
        foreach ($target as $state => $plan) {
            $results[$state] = [['route' => 'R', 'id' => 's1', 'plan' => $plan, 'unavailable' => $plan === null ? 'server_refused: x' : null]];
        }
        if ($results !== []) {
            $groups[] = ['role' => 'target', 'scale' => 100000, 'database' => 'd2', 'routes' => ['R'], 'results' => $results];
        }

        return ExplainReport::assemble(['scale' => 100000, 'small_scale' => 10000], $statements, $groups, $unplanned, []);
    }

    public function testAPlanThatChangesAtScaleIsMarkedAndKeepsBothRawPlans(): void
    {
        $r = self::report(self::INDEXED, ['default' => self::FULL_SCAN, 'low' => self::FULL_SCAN, 'high' => self::FULL_SCAN]);
        $st = $r['routes'][0]['statements'][0];

        $this->assertTrue($st['marks']['plan_changes_at_scale']);
        $this->assertFalse($st['marks']['plan_unstable']);
        $this->assertNull($st['marks']['explain_unavailable']);
        $this->assertSame(self::INDEXED, $st['small_scale']['default']['raw']);
        $this->assertSame('references|ALL|-', $st['target_scale']['default']['summary']['signature']);
        $this->assertSame(1, $r['totals']['plan_changes_at_scale']);
        $this->assertSame(0, $r['totals']['plan_unstable']);
    }

    public function testAPlanThatChangesWithTheStateIsUnstableAndAllStatesAreKept(): void
    {
        $r = self::report(self::INDEXED, ['default' => self::INDEXED, 'low' => self::FULL_SCAN, 'high' => self::INDEXED]);
        $st = $r['routes'][0]['statements'][0];

        $this->assertTrue($st['marks']['plan_unstable']);
        $this->assertFalse($st['marks']['plan_changes_at_scale']);
        $this->assertSame(['default', 'low', 'high'], array_keys($st['target_scale']));
        $this->assertSame(98000, $st['target_scale']['low']['summary']['tables'][0]['rows_examined_per_scan']);
        $this->assertSame(1, $r['totals']['plan_unstable']);
    }

    public function testThePlanThatDoesNotChangeHasNoMark(): void
    {
        $r = self::report(self::INDEXED, ['default' => self::INDEXED, 'low' => self::INDEXED, 'high' => self::INDEXED]);

        $this->assertSame(0, $r['totals']['plan_unstable'] + $r['totals']['plan_changes_at_scale'] + $r['totals']['explain_unavailable']);
    }

    public function testAStatementThatCouldNotBeExplainedAtTheTargetCarriesTheReason(): void
    {
        $r = self::report(self::INDEXED, ['default' => null, 'low' => null, 'high' => null]);
        $st = $r['routes'][0]['statements'][0];

        $this->assertSame('server_refused: x', $st['marks']['explain_unavailable']);
        $this->assertFalse($st['marks']['plan_changes_at_scale']);
        $this->assertSame(1, $r['totals']['explain_unavailable']);
    }

    public function testARouteThatWasNotPlannedIsExplainUnavailableForAllItsStatements(): void
    {
        $r = self::report(null, [], ['R' => 'route_not_in_the_task_list']);
        $st = $r['routes'][0]['statements'][0];

        $this->assertSame('route_not_in_the_task_list', $st['marks']['explain_unavailable']);
        $this->assertSame(['explain_unavailable' => 'route_not_in_the_task_list'], $st['small_scale']);
        $this->assertSame(['R' => 'route_not_in_the_task_list'], $r['unplanned_routes']);
    }

    public function testTheShapeOfTheOutput(): void
    {
        $r = self::report(self::INDEXED, ['default' => self::INDEXED, 'low' => self::INDEXED, 'high' => self::INDEXED]);

        $this->assertSame(['explain_version', 'scale', 'small_scale', 'states', 'capture', 'totals', 'routes', 'unplanned_routes', 'statistics'], array_keys($r));
        $this->assertSame(['default' => 20, 'low' => 4, 'high' => 200], $r['states']);
        $this->assertSame(['route', 'statements'], array_keys($r['routes'][0]));
        $this->assertSame(['id', 'kind', 'pattern', 'bindings_redacted', 'small_scale', 'target_scale', 'marks'], array_keys($r['routes'][0]['statements'][0]));
        $this->assertSame(['plan_unstable', 'plan_changes_at_scale', 'explain_unavailable', 'unavailable_states', 'unstable_states'], array_keys($r['routes'][0]['statements'][0]['marks']));
        $this->assertSame(['summary', 'raw'], array_keys($r['routes'][0]['statements'][0]['target_scale']['default']));
        $this->assertSame(['tables', 'signature', 'scan_rows_sum'], array_keys($r['routes'][0]['statements'][0]['target_scale']['default']['summary']));
        $this->assertJson(json_encode($r, JSON_THROW_ON_ERROR));
    }
}
