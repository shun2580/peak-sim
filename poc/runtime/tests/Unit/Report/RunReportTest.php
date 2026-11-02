<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Report;

use PeakSim\Runtime\Judge\Verdict;
use PeakSim\Runtime\Report\RunReport;
use PeakSim\Runtime\Route\RouteSpec;
use PeakSim\Runtime\Run\Attempt;
use PHPUnit\Framework\TestCase;

final class RunReportTest extends TestCase
{
    /** @param array<string, mixed> $static */
    private static function spec(string $uri = '/ng/n-plus-one', array $static = []): RouteSpec
    {
        return new RouteSpec($uri, 'GET', ['GET'], [], [], 'A@b', $static + [
            'query' => ['growth' => 'O(N)', 'lower_bound' => null, 'confidence' => 'high'],
            'memory' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'n_table' => 'orders', 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => [],
        ], true);
    }

    /**
     * @param list<string> $tables
     */
    private static function attempt(int $scale, ?int $queries, int $kind = Attempt::COLD, string $axis = 'N', array $tables = ['orders'], ?int $bytes = null, string $class = 'ok', bool $bypass = false): Attempt
    {
        return new Attempt('/ng/n-plus-one', 'GET', $scale, $axis, $kind, $class === 'ok' ? 200 : 500, $queries, ['mysql' => (int) $queries], $tables, $bytes ?? 100 + $scale, 1000, $bypass, $class, null);
    }

    /** @return list<Attempt> */
    private static function sweep(array $values, array $scales = [10, 100, 1000], string $axis = 'N'): array
    {
        $out = [];
        foreach ($scales as $k => $scale) {
            $out[] = self::attempt($scale, 99, Attempt::WARMUP, $axis);
            $out[] = self::attempt($scale, $values[$k], Attempt::COLD, $axis);
            $out[] = self::attempt($scale, 1, Attempt::WARM, $axis);
        }

        return $out;
    }

    public function test_only_the_cold_attempts_decide_the_verdict(): void
    {
        $row = RunReport::rowFor(self::spec(), self::sweep([11, 101, 1001]));

        $this->assertSame('measured_ON', $row['verdict']);
        $this->assertSame([11, 101, 1001], $row['values']);
        $this->assertSame([10, 100, 1000], $row['scales']);
        $this->assertSame('targeted:orders', $row['scope']);
        $this->assertTrue($row['tried']);
        $this->assertSame('ok', $row['class']);
    }

    public function test_a_row_carries_the_static_judgement_and_the_relation(): void
    {
        $row = RunReport::rowFor(self::spec(), self::sweep([11, 101, 1001]));

        $this->assertSame(['growth' => 'O(N)', 'lower_bound' => null, 'confidence' => 'high'], $row['static']);
        $this->assertSame('agree', $row['relation']);
    }

    public function test_a_disagreement_is_a_conflict_in_the_row(): void
    {
        $row = RunReport::rowFor(self::spec(), self::sweep([4, 4, 4]));

        $this->assertSame('measured_O1', $row['verdict']);
        $this->assertSame('conflict', $row['relation']);
    }

    public function test_a_flat_series_that_never_read_the_scaled_table_is_no_information(): void
    {
        $attempts = [];
        foreach ([10, 100, 1000] as $scale) {
            $attempts[] = self::attempt($scale, 3, Attempt::COLD, 'N', ['settings']);
        }

        $row = RunReport::rowFor(self::spec(), $attempts);

        $this->assertSame('no_info', $row['verdict']);
        $this->assertSame('untouched_data', $row['reason']);
        $this->assertSame('not_comparable', $row['relation']);
    }

    public function test_a_flat_series_whose_response_never_changed_in_size_is_no_information(): void
    {
        $attempts = [];
        foreach ([10, 100, 1000] as $scale) {
            $attempts[] = self::attempt($scale, 3, Attempt::COLD, 'N', ['orders'], 2);
        }

        $row = RunReport::rowFor(self::spec(), $attempts);

        $this->assertSame('no_info', $row['verdict']);
        $this->assertSame('suspect_empty', $row['reason']);
    }

    public function test_a_page_of_the_same_size_at_every_scale_is_still_o1_when_it_is_larger_than_the_empty_response(): void
    {
        $attempts = [self::attempt(0, 2, Attempt::COLD, 'N', ['orders'], 40)];
        foreach ([10, 100, 1000] as $scale) {
            $attempts[] = self::attempt($scale, 3, Attempt::COLD, 'N', ['orders'], 900);
        }

        $row = RunReport::rowFor(self::spec(), $attempts);

        $this->assertSame('measured_O1', $row['verdict']);
        $this->assertNull($row['reason']);
        $this->assertSame([10, 100, 1000], $row['scales'], 'scale 0 is a baseline for the response, not a point of the sweep');
        $this->assertSame([3, 3, 3], $row['values']);
    }

    public function test_a_response_equal_to_the_response_at_scale_zero_at_the_largest_scale_is_suspect_empty(): void
    {
        $attempts = [self::attempt(0, 2, Attempt::COLD, 'N', ['orders'], 40)];
        foreach ([10, 100, 1000] as $scale) {
            $attempts[] = self::attempt($scale, 3, Attempt::COLD, 'N', ['orders'], 40);
        }

        $row = RunReport::rowFor(self::spec(), $attempts);

        $this->assertSame('no_info', $row['verdict']);
        $this->assertSame('suspect_empty', $row['reason']);
    }

    public function test_only_the_largest_scale_is_compared_with_scale_zero(): void
    {
        $attempts = [self::attempt(0, 2, Attempt::COLD, 'N', ['orders'], 40)];
        foreach ([10 => 40, 100 => 40, 1000 => 700] as $scale => $bytes) {
            $attempts[] = self::attempt($scale, 3, Attempt::COLD, 'N', ['orders'], $bytes);
        }

        $this->assertSame('measured_O1', RunReport::rowFor(self::spec(), $attempts)['verdict']);
    }

    public function test_scale_zero_is_not_a_point_of_the_growth_sweep(): void
    {
        $attempts = [self::attempt(0, 2, Attempt::COLD, 'N', ['orders'], 40)];
        foreach ([10 => 11, 100 => 101, 1000 => 1001] as $scale => $queries) {
            $attempts[] = self::attempt($scale, $queries);
        }

        $row = RunReport::rowFor(self::spec(), $attempts);

        $this->assertSame('measured_ON', $row['verdict']);
        $this->assertSame([10, 100, 1000], $row['scales']);
    }

    public function test_a_failed_scale_zero_leaves_the_fallback_in_force(): void
    {
        $attempts = [self::attempt(0, null, Attempt::COLD, 'N', [], null, 'http_error')];
        foreach ([10, 100, 1000] as $scale) {
            $attempts[] = self::attempt($scale, 3, Attempt::COLD, 'N', ['orders'], 900);
        }

        $row = RunReport::rowFor(self::spec(), $attempts);

        $this->assertSame('measured_O1', $row['verdict'], 'without a usable scale 0 only a canonical empty body is suspect');
    }

    /** @return array<string, array{int, string}> */
    public static function emptyBodies(): array
    {
        return [
            'empty string' => [0, 'no_info'],
            'empty array []' => [2, 'no_info'],
            'empty object {}' => [2, 'no_info'],
            'data envelope {"data":[]}' => [11, 'no_info'],
            'an ordinary short body' => [12, 'measured_O1'],
            'a page' => [900, 'measured_O1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emptyBodies')]
    public function test_without_scale_zero_only_a_canonical_empty_body_that_never_changes_is_suspect_empty(int $bytes, string $expected): void
    {
        $attempts = [];
        foreach ([10, 100, 1000] as $scale) {
            $attempts[] = self::attempt($scale, 3, Attempt::COLD, 'N', ['orders'], $bytes);
        }

        $row = RunReport::rowFor(self::spec(), $attempts);

        $this->assertSame($expected, $row['verdict']);
        if ($expected === 'no_info') {
            $this->assertSame('suspect_empty', $row['reason']);
        }
    }

    public function test_a_body_of_the_empty_size_that_does_change_in_size_is_not_suspect_empty(): void
    {
        $attempts = [];
        foreach ([10 => 2, 100 => 2, 1000 => 3] as $scale => $bytes) {
            $attempts[] = self::attempt($scale, 3, Attempt::COLD, 'N', ['orders'], $bytes);
        }

        $this->assertSame('measured_O1', RunReport::rowFor(self::spec(), $attempts)['verdict']);
    }

    public function test_a_route_with_no_candidate_table_is_measured_in_the_global_scope(): void
    {
        $spec = self::spec('/x', ['n_table' => null, 'query' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null]]);

        $row = RunReport::rowFor($spec, self::sweep([11, 101, 1001]));

        $this->assertSame('global', $row['scope']);
        $this->assertSame('runtime_only', $row['relation']);
    }

    public function test_the_global_scope_counts_any_table_as_touched(): void
    {
        $spec = self::spec('/x', ['n_table' => null]);
        $attempts = [];
        foreach ([10, 100, 1000] as $scale) {
            $attempts[] = self::attempt($scale, 3, Attempt::COLD, 'N', ['whatever'], 100 + $scale);
        }

        $this->assertSame('measured_O1', RunReport::rowFor($spec, $attempts)['verdict']);
    }

    public function test_two_sweeps_make_a_product_only_for_a_targeted_scope(): void
    {
        $spec = self::spec('/p', ['m_table' => 'items', 'm_per' => 'orders']);
        $attempts = array_merge(self::sweep([10, 100, 1000]), self::sweep([3, 30, 300], [3, 30, 300], 'M'));

        $row = RunReport::rowFor($spec, $attempts);

        $this->assertSame('measured_ONM', $row['verdict']);
        $this->assertSame([3, 30, 300], $row['m_scales']);
    }

    public function test_a_gate_bypassed_attempt_marks_the_row(): void
    {
        $attempts = [];
        foreach ([10, 100, 1000] as $k => $scale) {
            $attempts[] = self::attempt($scale, [11, 101, 1001][$k], Attempt::COLD, 'N', ['orders'], null, 'ok', true);
        }

        $row = RunReport::rowFor(self::spec(), $attempts);

        $this->assertTrue($row['gate_bypass']);
    }

    public function test_a_route_that_could_not_be_hit_is_recorded_with_its_class_and_detail(): void
    {
        $row = RunReport::rowFor(self::spec(), [], 'unfilled_param', 'param: order');

        $this->assertFalse($row['tried']);
        $this->assertSame('unfilled_param', $row['class']);
        $this->assertSame('param: order', $row['reason_detail']);
        $this->assertSame('not_measured', $row['verdict']);
        $this->assertSame('not_comparable', $row['relation']);
    }

    public function test_attempts_that_all_failed_give_the_class_of_the_failure(): void
    {
        $attempts = [self::attempt(10, null, Attempt::COLD, 'N', [], null, 'forbidden'), self::attempt(100, null, Attempt::COLD, 'N', [], null, 'forbidden')];

        $row = RunReport::rowFor(self::spec(), $attempts);

        $this->assertTrue($row['tried']);
        $this->assertSame('forbidden', $row['class']);
        $this->assertSame('not_measured', $row['verdict']);
    }

    public function test_too_few_scales_is_not_measured(): void
    {
        $row = RunReport::rowFor(self::spec(), self::sweep([11, 101], [10, 100]));

        $this->assertSame('not_measured', $row['verdict']);
        $this->assertSame('insufficient_points', $row['reason']);
    }

    public function test_the_summary_counts_by_class_verdict_and_relation(): void
    {
        $report = new RunReport();
        $report->add(RunReport::rowFor(self::spec('/a'), self::sweep([11, 101, 1001])));
        $report->add(RunReport::rowFor(self::spec('/b'), self::sweep([4, 4, 4])));
        $report->add(RunReport::rowFor(self::spec('/c'), [], 'unfilled_param', 'param: order'));
        $report->add(RunReport::rowFor(self::spec('/d'), [], 'unfilled_param', 'param: x'));

        $s = $report->summary();

        $this->assertSame(4, $s['routes']);
        $this->assertSame(2, $s["tried"]);
        $this->assertSame(['ok' => 2, 'unfilled_param' => 2], $s['classes']);
        $this->assertSame(1, $s['verdicts']['measured_ON']);
        $this->assertSame(1, $s['verdicts']['measured_O1']);
        $this->assertSame(2, $s['verdicts']['not_measured']);
        $this->assertSame(0, $s['verdicts']['no_info']);
        $this->assertSame(1, $s['relations']['agree']);
        $this->assertSame(1, $s['relations']['conflict']);
        $this->assertSame(2, $s['relations']['not_comparable']);
        $this->assertSame(['GET /b'], $s['conflicts']);
    }

    public function test_the_markdown_puts_conflicts_before_everything_else_and_says_what_was_measured(): void
    {
        $report = new RunReport();
        $report->add(RunReport::rowFor(self::spec('/a'), self::sweep([11, 101, 1001])));
        $report->add(RunReport::rowFor(self::spec('/b'), self::sweep([4, 4, 4])));

        $md = $report->toMarkdown();

        $this->assertLessThan(strpos($md, '## Routes'), strpos($md, '## Conflicts'));
        $this->assertLessThan(strpos($md, '## Summary'), strpos($md, '## Conflicts'));
        $this->assertStringContainsString('GET /b', substr($md, (int) strpos($md, '## Conflicts'), 300));
        $this->assertStringContainsString('this input', $md);
        $this->assertStringContainsString('not fitted', $md);
    }

    public function test_the_markdown_has_no_conflicts_section_text_when_there_is_none(): void
    {
        $report = new RunReport();
        $report->add(RunReport::rowFor(self::spec('/a'), self::sweep([11, 101, 1001])));

        $this->assertStringContainsString('No conflicts', $report->toMarkdown());
    }

    public function test_the_markdown_header_names_the_profile_that_was_used(): void
    {
        $report = new RunReport();
        $report->add(RunReport::rowFor(self::spec('/a'), self::sweep([11, 101, 1001])));

        $md = $report->withProfile('fixtures')->toMarkdown();

        $this->assertStringContainsString("\nprofile: fixtures\n", $md);
        $this->assertLessThan(strpos($md, '## Conflicts'), strpos($md, 'profile: fixtures'));
    }

    public function test_the_markdown_says_profile_none_when_no_profile_was_used(): void
    {
        $report = new RunReport();
        $report->add(RunReport::rowFor(self::spec('/a'), self::sweep([11, 101, 1001])));

        foreach ([$report->toMarkdown(), $report->withProfile(null)->toMarkdown()] as $md) {
            $this->assertStringContainsString("\nprofile: none\n", $md);
            $this->assertStringNotContainsString('with profile', $md);
        }
    }

    public function test_each_statement_says_it_was_reached_with_the_profile_only_when_one_was_used(): void
    {
        $report = new RunReport();
        $report->add(RunReport::rowFor(self::spec('/a'), self::sweep([11, 101, 1001])));
        $report->add(RunReport::rowFor(self::spec('/b'), self::sweep([4, 4, 4])));

        foreach ($report->withProfile('fixtures')->rows() as $row) {
            $this->assertStringEndsWith(' (with profile: fixtures)', $row['statement']);
        }
        foreach ($report->rows() as $row) {
            $this->assertStringNotContainsString('profile', $row['statement']);
        }
        $this->assertStringContainsString('(with profile: fixtures)', $report->withProfile('fixtures')->toJsonLines());
        $this->assertStringNotContainsString('profile', $report->toJsonLines());
    }

    public function test_naming_a_profile_changes_nothing_but_the_statement_and_the_header(): void
    {
        $report = new RunReport();
        $report->add(RunReport::rowFor(self::spec('/a'), self::sweep([11, 101, 1001])));
        $with = $report->withProfile('fixtures');

        $strip = static fn (array $rows): array => array_map(static function (array $r): array {
            unset($r['statement']);

            return $r;
        }, $rows);
        $this->assertSame($strip($report->rows()), $strip($with->rows()));
        $this->assertSame($report->summary(), $with->summary());
        $this->assertSame(
            str_replace("profile: none\n", "profile: fixtures\n", $report->toMarkdown()),
            $with->toMarkdown(),
        );
    }

    public function test_the_json_lines_are_one_row_per_line_sorted_by_route_and_stable(): void
    {
        $report = new RunReport();
        $report->add(RunReport::rowFor(self::spec('/b'), []));
        $report->add(RunReport::rowFor(self::spec('/a'), self::sweep([11, 101, 1001])));

        $lines = explode("\n", rtrim($report->toJsonLines(), "\n"));

        $this->assertCount(2, $lines);
        $this->assertSame('/a', json_decode($lines[0], true)['route']);
        $this->assertSame('/b', json_decode($lines[1], true)['route']);
        $this->assertSame($report->toJsonLines(), $report->toJsonLines());
    }

    public function test_an_unfinished_run_with_no_routes_still_summarises(): void
    {
        $s = (new RunReport())->summary();

        $this->assertSame(0, $s['routes']);
        $this->assertSame([], $s['conflicts']);
    }

    // -- "the data may not have arrived": the same bytes and the same rows read at every scale --

    /** @param array<int, array<string, int>|null> $rowsByScale @param array<int, int> $bytesByScale */
    private static function readSweep(array $rowsByScale, array $bytesByScale, array $queries = [4, 4, 4], string $uri = '/ng/n-plus-one'): array
    {
        $out = [];
        $k = 0;
        foreach ([10, 100, 1000] as $scale) {
            $out[] = new Attempt($uri, 'GET', $scale, 'N', Attempt::COLD, 200, $queries[$k++], ['mysql' => 4], ['orders'], $bytesByScale[$scale], 1000, false, 'ok', null, rowsRead: $rowsByScale[$scale]);
        }

        return $out;
    }

    public function test_the_same_bytes_and_the_same_rows_at_every_scale_is_marked_as_a_doubt(): void
    {
        $same = [10 => ['orders' => 5], 100 => ['orders' => 5], 1000 => ['orders' => 5]];
        $row = RunReport::rowFor(self::spec(), self::readSweep($same, [10 => 900, 100 => 900, 1000 => 900]));

        $this->assertTrue($row['data_not_arriving_suspected']);
        $this->assertSame('measured_O1', $row['verdict'], 'the rule that judges is not changed by the mark');
        $this->assertStringContainsString('rows', $row['data_doubt']);
    }

    public function test_rows_that_grow_clear_the_doubt_even_when_the_bytes_do_not_change(): void
    {
        $grow = [10 => ['orders' => 5], 100 => ['orders' => 50], 1000 => ['orders' => 50]];
        $row = RunReport::rowFor(self::spec(), self::readSweep($grow, [10 => 900, 100 => 900, 1000 => 900]));

        $this->assertArrayNotHasKey('data_not_arriving_suspected', $row);
    }

    public function test_bytes_that_change_clear_the_doubt_even_when_the_rows_do_not(): void
    {
        $same = [10 => ['orders' => 5], 100 => ['orders' => 5], 1000 => ['orders' => 5]];
        $row = RunReport::rowFor(self::spec(), self::readSweep($same, [10 => 900, 100 => 901, 1000 => 902]));

        $this->assertArrayNotHasKey('data_not_arriving_suspected', $row);
    }

    public function test_only_the_rows_of_the_scaled_tables_count(): void
    {
        // customers is not a scaled table: its rows moving does not clear the doubt about orders
        $rows = [10 => ['orders' => 5, 'customers' => 1], 100 => ['orders' => 5, 'customers' => 9], 1000 => ['orders' => 5, 'customers' => 30]];
        $row = RunReport::rowFor(self::spec(), self::readSweep($rows, [10 => 900, 100 => 900, 1000 => 900]));

        $this->assertTrue($row['data_not_arriving_suspected']);
    }

    public function test_a_global_scope_counts_the_rows_of_every_table(): void
    {
        $rows = [10 => ['orders' => 5, 'customers' => 1], 100 => ['orders' => 5, 'customers' => 9], 1000 => ['orders' => 5, 'customers' => 30]];
        $row = RunReport::rowFor(self::spec('/x', ['n_table' => null]), self::readSweep($rows, [10 => 900, 100 => 900, 1000 => 900], uri: '/x'));

        $this->assertArrayNotHasKey('data_not_arriving_suspected', $row);
    }

    public function test_without_the_counts_nothing_is_said(): void
    {
        $row = RunReport::rowFor(self::spec(), self::readSweep([10 => null, 100 => null, 1000 => null], [10 => 900, 100 => 900, 1000 => 900]));

        $this->assertArrayNotHasKey('data_not_arriving_suspected', $row);
    }

    public function test_one_point_cannot_be_compared(): void
    {
        $one = [new Attempt('/ng/n-plus-one', 'GET', 10, 'N', Attempt::COLD, 200, 4, ['mysql' => 4], ['orders'], 900, 1000, false, 'ok', null, rowsRead: ['orders' => 5])];

        $this->assertArrayNotHasKey('data_not_arriving_suspected', RunReport::rowFor(self::spec(), $one));
    }

    public function test_the_mark_reaches_the_summary_and_the_markdown(): void
    {
        $same = [10 => ['orders' => 5], 100 => ['orders' => 5], 1000 => ['orders' => 5]];
        $report = new RunReport();
        $report->add(RunReport::rowFor(self::spec(), self::readSweep($same, [10 => 900, 100 => 900, 1000 => 900])));
        $report->add(RunReport::rowFor(self::spec('/other'), self::sweep([11, 101, 1001])));

        $this->assertSame(['/ng/n-plus-one'], $report->summary()['data_not_arriving_suspected']);
        $this->assertStringContainsString('data may not have arrived', $report->toMarkdown());
    }

    public function test_a_summary_with_no_doubt_has_no_such_entry(): void
    {
        $report = new RunReport();
        $report->add(RunReport::rowFor(self::spec(), self::sweep([11, 101, 1001])));

        $this->assertArrayNotHasKey('data_not_arriving_suspected', $report->summary());
    }
}
