<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Report;

use PeakSim\Runtime\Judge\Verdict;
use PeakSim\Runtime\Report\Comparison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComparisonTest extends TestCase
{
    private const SCALES = [10, 100, 1000];

    private static function o1(bool $bypass = false): Verdict
    {
        return Verdict::fromSweep('targeted:orders', self::SCALES, [4, 4, 4], gateBypass: $bypass);
    }

    private static function on(bool $bypass = false): Verdict
    {
        return Verdict::fromSweep('targeted:orders', self::SCALES, [11, 101, 1001], gateBypass: $bypass);
    }

    private static function onm(): Verdict
    {
        return Verdict::fromSweeps('targeted:orders', self::SCALES, [10, 100, 1000], [3, 30, 300], [3, 30, 300]);
    }

    /** @return array{growth: ?string, lower_bound: ?string, confidence: ?string} */
    private static function st(?string $growth, ?string $lb = null, ?string $conf = null): array
    {
        return ['growth' => $growth, 'lower_bound' => $lb, 'confidence' => $conf];
    }

    public function test_a_static_verdict_and_a_runtime_verdict_that_say_the_same_agree(): void
    {
        $this->assertSame('agree', Comparison::relate(self::st('O(1)', null, 'high'), self::o1()));
        $this->assertSame('agree', Comparison::relate(self::st('O(N)', null, 'high'), self::on()));
        $this->assertSame('agree', Comparison::relate(self::st('O(N*M)', null, 'medium'), self::onm()));
    }

    public function test_a_determinate_static_verdict_that_the_runtime_contradicts_is_a_conflict(): void
    {
        $this->assertSame('conflict', Comparison::relate(self::st('O(1)', null, 'high'), self::on()));
        $this->assertSame('conflict', Comparison::relate(self::st('O(N)', null, 'high'), self::o1()));
        $this->assertSame('conflict', Comparison::relate(self::st('O(N)', null, 'high'), self::onm()));
    }

    public function test_a_static_unknown_that_the_runtime_decides_is_runtime_only(): void
    {
        $this->assertSame('runtime_only', Comparison::relate(self::st('unknown'), self::o1()));
        $this->assertSame('runtime_only', Comparison::relate(self::st('unknown'), self::on()));
        $this->assertSame('runtime_only', Comparison::relate(self::st(null), self::on()));
    }

    public function test_a_runtime_result_at_or_above_the_static_lower_bound_leaves_the_runtime_deciding(): void
    {
        $this->assertSame('runtime_only', Comparison::relate(self::st('unknown', 'O(N)'), self::on()));
        $this->assertSame('runtime_only', Comparison::relate(self::st('unknown', 'O(N)'), self::onm()));
    }

    public function test_a_runtime_result_below_the_static_lower_bound_is_static_stronger(): void
    {
        $this->assertSame('static_stronger', Comparison::relate(self::st('unknown', 'O(N)'), self::o1()));
        $this->assertSame('static_stronger', Comparison::relate(self::st('unknown', 'O(N*M)'), self::on()));
    }

    /** @return array<string, array{Verdict}> */
    public static function undecided(): array
    {
        return [
            'inconclusive' => [Verdict::fromSweep('global', self::SCALES, [100, 1000, 2239])],
            'no information' => [Verdict::fromSweep('global', self::SCALES, [2, 2, 2], untouchedData: true)],
            'not measured' => [Verdict::notMeasured('unfilled_param')],
        ];
    }

    #[DataProvider('undecided')]
    public function test_a_runtime_result_that_decided_nothing_is_not_comparable(Verdict $v): void
    {
        $this->assertSame('not_comparable', Comparison::relate(self::st('O(1)', null, 'high'), $v));
        $this->assertSame('not_comparable', Comparison::relate(self::st('unknown', 'O(N)'), $v));
    }

    public function test_a_gate_bypassed_measurement_never_reports_a_conflict_or_a_stronger_static(): void
    {
        $conflict = Comparison::compare(self::st('O(1)', null, 'high'), self::on(true));
        $stronger = Comparison::compare(self::st('unknown', 'O(N)'), self::o1(true));

        $this->assertSame('not_comparable', $conflict['relation']);
        $this->assertStringContainsString('conflict', (string) $conflict['note']);
        $this->assertSame('not_comparable', $stronger['relation']);
        $this->assertStringContainsString('static_stronger', (string) $stronger['note']);
    }

    public function test_a_gate_bypassed_agreement_is_kept_but_marked_as_reference(): void
    {
        $agree = Comparison::compare(self::st('O(N)', null, 'high'), self::on(true));

        $this->assertSame('agree', $agree['relation']);
        $this->assertStringContainsString('reference', (string) $agree['note']);
    }

    public function test_an_ordinary_comparison_has_no_note(): void
    {
        $this->assertNull(Comparison::compare(self::st('O(1)', null, 'high'), self::o1())['note']);
    }

    /** @return array<string, array{?string, Verdict, string}> */
    public static function expected(): array
    {
        return [
            'match o1' => ['O(1)', self::o1(), 'match'],
            'match on' => ['O(N)', self::on(), 'match'],
            'match onm' => ['O(N*M)', self::onm(), 'match'],
            'mismatch is reported as is' => ['O(N)', self::o1(), 'mismatch'],
            'mismatch the other way' => ['O(1)', self::on(), 'mismatch'],
            'inconclusive' => ['O(N)', Verdict::fromSweep('global', self::SCALES, [100, 1000, 2239]), 'inconclusive'],
            'no information' => ['O(1)', Verdict::fromSweep('global', self::SCALES, [2, 2, 2], suspectEmpty: true), 'no_info'],
            'not measured' => ['O(N)', Verdict::notMeasured('forbidden'), 'not_measured'],
            'unknown expectation filled' => ['unknown', self::on(), 'unknown_filled'],
            'null expectation filled' => [null, self::o1(), 'unknown_filled'],
            'unknown expectation left open' => ['unknown', Verdict::notMeasured('exception'), 'unknown_open'],
        ];
    }

    #[DataProvider('expected')]
    public function test_the_runtime_is_set_against_the_fixture_expectation(?string $query, Verdict $v, string $expected): void
    {
        $this->assertSame($expected, Comparison::againstExpected($query, $v));
    }

    public function test_the_fixture_expectations_load(): void
    {
        $routes = Comparison::loadExpected(__DIR__ . '/../../../../../fixtures/expected.json');

        $this->assertCount(24, $routes);
        $this->assertSame('/ok/eager', $routes[0]['route']);
        $this->assertSame('O(1)', $routes[0]['query']);
        $this->assertArrayHasKey('n_table', $routes[0]);
    }

    public function test_a_file_that_is_not_an_expectation_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Comparison::loadExpected(__DIR__ . '/../../vectors/growth.json');
    }

    public function test_the_table_puts_expected_static_and_runtime_side_by_side(): void
    {
        $expected = [
            ['route' => '/ok/eager', 'group' => 'ok', 'query' => 'O(1)'],
            ['route' => '/ng/n-plus-one', 'group' => 'ng', 'query' => 'O(N)'],
            ['route' => '/unknown/dynamic', 'group' => 'unknown', 'query' => 'unknown'],
            ['route' => '/ng/missing', 'group' => 'ng', 'query' => 'O(N)'],
        ];
        $static = [
            '/ok/eager' => self::st('O(1)', null, 'high'),
            '/ng/n-plus-one' => self::st('O(N)', null, 'high'),
            '/unknown/dynamic' => self::st('unknown'),
        ];
        $verdicts = [
            '/ok/eager' => self::o1(),
            '/ng/n-plus-one' => self::o1(),
            '/unknown/dynamic' => self::on(),
        ];

        $rows = Comparison::table($expected, $static, $verdicts);

        $this->assertCount(4, $rows);
        $this->assertSame('agree', $rows[0]['relation']);
        $this->assertSame('match', $rows[0]['vs_expected']);
        $this->assertSame('conflict', $rows[1]['relation']);
        $this->assertSame('mismatch', $rows[1]['vs_expected']);
        $this->assertSame('runtime_only', $rows[2]['relation']);
        $this->assertSame('unknown_filled', $rows[2]['vs_expected']);
        $this->assertSame('not_measured', $rows[3]['vs_expected']);
        $this->assertSame('not_comparable', $rows[3]['relation']);
        $this->assertSame('route_not_run', $rows[3]['reason']);
    }

    public function test_the_markdown_lists_disagreements_first_and_fits_nothing(): void
    {
        $rows = Comparison::table(
            [['route' => '/ok/eager', 'group' => 'ok', 'query' => 'O(1)'], ['route' => '/ng/n-plus-one', 'group' => 'ng', 'query' => 'O(N)']],
            ['/ok/eager' => self::st('O(1)', null, 'high'), '/ng/n-plus-one' => self::st('O(N)', null, 'high')],
            ['/ok/eager' => self::o1(), '/ng/n-plus-one' => self::o1()],
        );

        $md = Comparison::toMarkdown($rows);

        $this->assertLessThan(strpos($md, '| /ok/eager'), strpos($md, 'Disagreements'));
        $this->assertStringContainsString('/ng/n-plus-one', substr($md, (int) strpos($md, 'Disagreements'), 400));
        $this->assertStringContainsString('not fitted', $md);
        $this->assertStringContainsString('this input', $md);
    }

    public function test_the_markdown_says_so_when_nothing_disagrees(): void
    {
        $rows = Comparison::table(
            [['route' => '/ok/eager', 'group' => 'ok', 'query' => 'O(1)']],
            ['/ok/eager' => self::st('O(1)', null, 'high')],
            ['/ok/eager' => self::o1()],
        );

        $this->assertStringContainsString('No disagreements', Comparison::toMarkdown($rows));
    }
}
