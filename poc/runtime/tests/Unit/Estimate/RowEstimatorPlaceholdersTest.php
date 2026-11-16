<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\RowEstimator;
use PHPUnit\Framework\TestCase;

/**
 * `--with-placeholders`: the number of values of the capture's representative statement is added per type and nothing else changes. Made-up capture with
 * the kinds of statement the rule tells apart: a plain statement, one with a list, one with two lists, an insert of many rows, one with a literal the pattern shows as `?`, a list of one id, and a type with no representative.
 */
final class RowEstimatorPlaceholdersTest extends TestCase
{
    private const N = 100000;

    /**
     * The statement as the app sent it: each `in (?)` of the pattern becomes a list of the given length, in order.
     *
     * @param list<int> $lists
     */
    private static function expand(string $pattern, array $lists): string
    {
        $i = 0;

        return (string) preg_replace_callback('/\bin \(\?\)/', static function () use (&$i, $lists): string {
            return 'in (' . implode(', ', array_fill(0, $lists[$i++], '?')) . ')';
        }, $pattern);
    }

    /**
     * @param list<int> $lists  the length of each `in (?)` list of the sent statement
     * @param int       $others the values outside lists
     *
     * @return array<string, mixed>
     */
    private static function type(string $id, string $kind, string $pattern, array $lists, int $others, int $scale = 30000, ?string $sql = null): array
    {
        $perScale = [];
        foreach ([100, 1000, 10000] as $s) {
            $perScale[(string) $s] = ['count' => 1, 'texts' => 1, 'rows_sum' => 3, 'rows_min' => 3, 'rows_max' => 3, 'unread' => 0];
        }
        $sent = $sql ?? self::expand($pattern, $lists);

        return [
            'id' => $id, 'kind' => $kind, 'pattern' => $pattern,
            'representative' => ['scale' => $scale, 'sql' => $sent, 'bindings' => array_fill(0, $others + array_sum($lists), 7)],
            'per_scale' => $perScale,
            'count_fit' => ['fit_status' => 'ok', 'a' => 1.0, 'b' => 0.0, 'k' => 0.0, 'sse' => 0.0, 'scales' => [100, 1000, 10000]],
        ];
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private static function fixture(): array
    {
        $capture = ['capture_version' => 1, 'app' => 'demo', 'scales' => [100, 1000, 10000], 'representative_scale' => 30000, 'routes' => [[
            'route' => 'GET /r', 'status' => 'captured',
            'statement_types' => [
                self::type('sPlain', 'select', 'select * from `t` where `a` = ? and `b` = ?', [], 2),
                self::type('sList', 'select', 'select * from `t` where `type` = ? and `id` in (?)', [600], 1),
                self::type('sTwo', 'select', 'select * from `a` where ((`k` = ? and `i` in (?)) or (`k` = ? and `i` in (?)))', [6000, 12000], 2),
                self::type('sInsert', 'insert', 'insert into `t` (`x`, `y`) values (?, ?)', [], 4000, 30000, 'insert into `t` (`x`, `y`) values ' . implode(', ', array_fill(0, 2000, '(?, ?)'))),
                // the pattern shows `limit ?` but the app wrote `limit 20`: no value goes with it
                self::type('sLiteral', 'select', 'select * from `t` where `id` in (?) limit ?', [50], 0, 30000, 'select * from `t` where `id` in (' . implode(', ', array_fill(0, 50, '?')) . ') limit 20'),
                self::type('sSingle', 'select', 'select * from `t` where `id` in (?)', [1], 0),
                ['id' => 'sNone', 'kind' => 'select', 'pattern' => 'select 1', 'representative' => null, 'per_scale' => ['100' => ['count' => 1, 'texts' => 1, 'rows_sum' => 1, 'rows_min' => 1, 'rows_max' => 1, 'unread' => 0], '1000' => ['count' => 1, 'texts' => 1, 'rows_sum' => 1, 'rows_min' => 1, 'rows_max' => 1, 'unread' => 0], '10000' => ['count' => 1, 'texts' => 1, 'rows_sum' => 1, 'rows_min' => 1, 'rows_max' => 1, 'unread' => 0]], 'count_fit' => ['fit_status' => 'ok', 'a' => 1.0, 'b' => 0.0, 'k' => 0.0, 'sse' => 0.0, 'scales' => [100, 1000, 10000]]],
            ],
        ]]];
        $explain = ['explain_version' => 1, 'scale' => self::N, 'small_scale' => 10000, 'routes' => []];

        return [$capture, $explain];
    }

    public function test_without_the_flag_nothing_is_added_and_the_output_is_the_same_bytes(): void
    {
        [$capture, $explain] = self::fixture();
        $plain = RowEstimator::estimate($capture, $explain, RowEstimator::H1, self::N, null, true);
        $off = RowEstimator::estimate($capture, $explain, RowEstimator::H1, self::N, null, true, false, false);
        $this->assertSame(json_encode($plain), json_encode($off));
        $this->assertStringNotContainsString('placeholders', (string) json_encode($plain));
    }

    public function test_the_flag_adds_the_key_and_leaves_every_other_figure_as_it_was(): void
    {
        [$capture, $explain] = self::fixture();
        $plain = RowEstimator::estimate($capture, $explain, RowEstimator::H1, self::N, null, true);
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1, self::N, null, true, false, true);
        $this->assertNotSame($plain, $with);
        foreach ($with['routes'] as &$route) {
            foreach ($route['types'] as &$type) {
                unset($type['placeholders']);
            }
        }
        unset($route, $type);
        $this->assertSame($plain, $with, 'with the new key taken out, the output is the one without the flag');
    }

    public function test_the_figure_of_each_kind_of_statement(): void
    {
        [$capture, $explain] = self::fixture();
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1, self::N, null, true, false, true);
        $by = array_column($with['routes'][0]['types'], null, 'id');
        // two values outside any list
        $this->assertSame(['scale' => 30000, 'values' => 2, 'fixed' => 2], $by['sPlain']['placeholders']);
        // one value for the type and a list of 600
        $this->assertSame(['scale' => 30000, 'values' => 601, 'fixed' => 1], $by['sList']['placeholders']);
        // two types and two lists of 6,000 and 12,000
        $this->assertSame(['scale' => 30000, 'values' => 18002, 'fixed' => 2], $by['sTwo']['placeholders']);
        // an insert of many rows: every value is the list
        $this->assertSame(['scale' => 30000, 'values' => 4000, 'fixed' => 0], $by['sInsert']['placeholders']);
        // a literal the app wrote into the statement is not a value: the figure is read from the statement and not from the pattern
        $this->assertSame(['scale' => 30000, 'values' => 50, 'fixed' => 0], $by['sLiteral']['placeholders']);
        // a list of one id is a list too (it is scaled with the table; it leans to marking too much, never too little)
        $this->assertSame(['scale' => 30000, 'values' => 1, 'fixed' => 0], $by['sSingle']['placeholders']);
    }

    public function test_a_type_with_no_representative_gets_no_figure(): void
    {
        [$capture, $explain] = self::fixture();
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1, self::N, null, true, false, true);
        $by = array_column($with['routes'][0]['types'], null, 'id');
        $this->assertArrayNotHasKey('placeholders', $by['sNone']);
    }
}
