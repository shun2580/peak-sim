<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\RowEstimator;
use PeakSim\Runtime\Simulate\Load\EstimateFormulas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The statement text of a type (`pattern`) is kept whole in the estimate. It used to stop at 160 characters, so anything past that point (a
 * `limit … offset …` at the end of a long statement) could not be found in the estimate file. Only the text changes: no count, no rows per run, no
 * formula, no mark moves with its length.
 */
final class RowEstimatorPatternTest extends TestCase
{
    /** a statement text longer than 160 characters, with the part a reader looks for at its very end */
    private const TAIL = ' order by `t`.`id` asc limit ? offset ?';

    private static function longText(string $id): string
    {
        return 'select ' . $id . ' ' . str_repeat('x', 300) . self::TAIL;
    }

    /**
     * @param array<string, mixed> $capture
     *
     * @return array<string, mixed> the capture with every statement type's pattern replaced by $make(id)
     */
    private static function withPatterns(array $capture, callable $make): array
    {
        foreach ($capture['routes'] as &$route) {
            foreach ($route['statement_types'] as &$type) {
                $type['pattern'] = $make((string) $type['id']);
            }
            unset($type);
        }
        unset($route);

        return $capture;
    }

    /**
     * @param array<string, mixed> $estimate
     *
     * @return array<string, mixed> the same estimate with every `pattern` (of a type and of an unavailable entry) blanked, to compare the figures alone
     */
    private static function withoutPatterns(array $estimate): array
    {
        foreach ($estimate['routes'] as &$route) {
            foreach ($route['types'] as &$type) {
                unset($type['pattern']);
            }
            unset($type);
            foreach ($route['unavailable'] as &$u) {
                unset($u['pattern']);
            }
            unset($u);
        }
        unset($route);

        return $estimate;
    }

    public function test_a_pattern_longer_than_160_characters_is_kept_whole(): void
    {
        [$capture, $explain] = PointRuleFixtures::fixture();
        $capture = self::withPatterns($capture, self::longText(...));
        $estimate = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE);
        $seen = 0;
        foreach ($estimate['routes'] as $route) {
            foreach ($route['types'] as $type) {
                $this->assertSame(self::longText($type['id']), $type['pattern']);
                $this->assertGreaterThan(160, strlen($type['pattern']));
                $this->assertStringEndsWith(self::TAIL, $type['pattern']);
                $seen++;
            }
        }
        $this->assertGreaterThan(10, $seen);
    }

    public function test_a_short_pattern_is_as_it_was(): void
    {
        [$capture, $explain] = PointRuleFixtures::fixture();
        $estimate = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE);
        $this->assertSame('select p1_scan', $estimate['routes'][0]['types'][0]['pattern']);
    }

    /** @return array<string, array{string}> */
    public static function candidates(): array
    {
        return ['H1' => ['H1'], 'H2' => ['H2'], 'H3' => ['H3'], 'H1_RANGE' => ['H1_RANGE']];
    }

    #[DataProvider('candidates')]
    public function test_no_figure_of_the_estimate_depends_on_how_long_the_pattern_is(string $candidate): void
    {
        [$capture, $explain] = PointRuleFixtures::fixture();
        $short = RowEstimator::estimate($capture, $explain, $candidate, null, null, true);
        $long = RowEstimator::estimate(self::withPatterns($capture, self::longText(...)), $explain, $candidate, null, null, true);
        $this->assertNotSame($short, $long, 'the texts themselves differ');
        // everything else (counts, rows per run, formulas, marks, totals) is the same value to the last digit
        $this->assertSame(self::withoutPatterns($short), self::withoutPatterns($long));
    }

    public function test_an_unavailable_type_names_its_whole_pattern(): void
    {
        [$capture, $explain] = PointRuleFixtures::fixture();
        $capture = self::withPatterns($capture, self::longText(...));
        // a type whose count formula was not made is unavailable and is listed with its pattern
        $capture['routes'][0]['statement_types'][0]['count_fit'] = ['fit_status' => 'insufficient_scales'];
        $estimate = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE);
        $this->assertCount(1, $estimate['routes'][0]['unavailable']);
        $this->assertSame(self::longText('p1_scan'), $estimate['routes'][0]['unavailable'][0]['pattern']);
    }

    public function test_the_formulas_file_and_the_readers_of_the_estimate_carry_a_long_pattern_through(): void
    {
        [$capture, $explain] = PointRuleFixtures::fixture();
        $capture = self::withPatterns($capture, self::longText(...));
        $estimate = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, null, null, true);
        $evaluated = EstimateFormulas::fromDocument($estimate)->evaluate('GET /moved', PointRuleFixtures::N);
        $this->assertSame('ok', $evaluated['cost']);
        foreach ($evaluated['types'] as $type) {
            $this->assertSame(self::longText($type['id']), $type['pattern']);
        }
    }
}
