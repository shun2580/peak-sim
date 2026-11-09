<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Load;

/**
 * A made-up estimate document (the `bin/estimate --with-formulas` form) whose figures are easy to work out by hand, and made-up scenario files.
 *
 *   GET /a        table pages, not marked. Types:
 *                   t1  count 2 (a=2 b=0 k=0)      rows per run 10 + 0.1 * N      measured at 100 / 1,000 / 10,000
 *                   t2  count 0.01 * N             rows per run 3 (held)           measured at 100 / 1,000
 *                 peak memory 1000 + 2 * N
 *   POST /m       table books, marked. Types:
 *                   u1  count 1                    rows per run N, upper side 5 x that
 *                   u2  count 3                    rows per run 0, upper side 40 (H1 value 0: the upper value is held as it is)
 *                   u3  count 1                    rows per run 7, no upper side of its own
 *   GET /inc      table pages, a statement type with no count formula: incomplete
 *   GET /axisless complete, no table that scales
 *   GET /nopred   known to the static analysis (the route index) only: no estimate
 */
final class Fixtures
{
    public const ESTIMATE_PATH = '/est/estimate.json';

    public const SCENARIO_PATH = '/in/scenario.yaml';

    /** @return array<string, mixed> */
    public static function estimateDocument(): array
    {
        $type = static fn (string $id, array $count, array $perExec, array $scales, ?array $upper = null, bool $marked = false): array => [
            'id' => $id, 'kind' => 'select', 'pattern' => 'select ' . $id,
            'marks' => ['plan_changes_at_scale' => $marked, 'plan_unstable' => false],
            'formula' => ['count' => $count, 'per_exec' => $perExec, 'scales' => $scales, 'upper' => $upper],
        ];

        return [
            'schema_version' => '1', 'candidate' => 'H1_RANGE', 'at' => 200000, 'small_scale' => 10000, 'app' => 'demo',
            'capture_scales' => [100, 1000, 10000],
            'route_index' => [
                ['route' => 'GET /a', 'axis' => 'N', 'table' => 'pages', 'in_estimate' => true],
                ['route' => 'GET /axisless', 'axis' => null, 'table' => null, 'in_estimate' => true],
                ['route' => 'GET /inc', 'axis' => 'N', 'table' => 'pages', 'in_estimate' => true],
                ['route' => 'GET /nopred', 'axis' => 'N', 'table' => 'pages', 'in_estimate' => false],
                ['route' => 'POST /m', 'axis' => 'N', 'table' => 'books', 'in_estimate' => true],
            ],
            'routes' => [
                [
                    'route' => 'GET /a', 'status' => 'captured', 'complete' => true, 'marked' => false, 'table' => 'pages', 'axis' => 'N',
                    'peak_memory' => ['status' => 'predicted', 'value' => 0.0], 'peak_memory_formula' => ['a' => 1000.0, 'b' => 2.0, 'k' => 1.0],
                    'types' => [
                        $type('t1', ['a' => 2.0, 'b' => 0.0, 'k' => 0.0], ['basis' => 'fit', 'a' => 10.0, 'b' => 0.1, 'k' => 1.0], [100, 1000, 10000]),
                        $type('t2', ['a' => 0.0, 'b' => 0.01, 'k' => 1.0], ['basis' => 'held_from_largest_scale', 'value' => 3.0], [100, 1000]),
                    ],
                ],
                [
                    'route' => 'POST /m', 'status' => 'captured', 'complete' => true, 'marked' => true, 'table' => 'books', 'axis' => 'N',
                    'peak_memory' => ['status' => 'not_predicted', 'reason' => 'insufficient_scales'], 'peak_memory_formula' => null,
                    'types' => [
                        $type('u1', ['a' => 1.0, 'b' => 0.0, 'k' => 0.0], ['basis' => 'fit', 'a' => 0.0, 'b' => 1.0, 'k' => 1.0], [100, 1000, 10000], ['at_n' => 1000000.0, 'h1_at_n' => 200000.0, 'ratio' => 5.0], true),
                        $type('u2', ['a' => 3.0, 'b' => 0.0, 'k' => 0.0], ['basis' => 'fit', 'a' => 0.0, 'b' => 0.0, 'k' => 0.0], [100, 1000, 10000], ['at_n' => 40.0, 'h1_at_n' => 0.0, 'ratio' => null], true),
                        $type('u3', ['a' => 1.0, 'b' => 0.0, 'k' => 0.0], ['basis' => 'fit', 'a' => 7.0, 'b' => 0.0, 'k' => 0.0], [100, 1000, 10000]),
                    ],
                ],
                [
                    'route' => 'GET /inc', 'status' => 'captured', 'complete' => false, 'marked' => false, 'table' => 'pages', 'axis' => 'N',
                    'peak_memory' => ['status' => 'not_given'], 'peak_memory_formula' => null,
                    'unavailable' => [['id' => 'x1', 'pattern' => 'select x1', 'reason' => 'count_formula:insufficient_scales']],
                    'types' => [['id' => 'x1', 'kind' => 'select', 'pattern' => 'select x1', 'unavailable' => 'count_formula:insufficient_scales']],
                ],
                [
                    'route' => 'GET /axisless', 'status' => 'captured', 'complete' => true, 'marked' => false, 'table' => null, 'axis' => null,
                    'peak_memory' => ['status' => 'not_given'], 'peak_memory_formula' => null,
                    'types' => [$type('v1', ['a' => 1.0, 'b' => 0.0, 'k' => 0.0], ['basis' => 'fit', 'a' => 1.0, 'b' => 0.0, 'k' => 0.0], [100, 1000, 10000])],
                ],
            ],
        ];
    }

    public static function estimateJson(): string
    {
        return json_encode(self::estimateDocument(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** The scenario file of section 3.4 with the routes of the made-up estimate. A target's observed is a number that appears nowhere else. */
    public const TARGET_OBSERVED = '0.74317';

    public static function scenarioYaml(): string
    {
        return <<<'YAML'
            # made-up scenario
            schema_version: "0.1"
            resources:
              db_cpu:
                cores: 2              # CPUs given to the database
                threshold: 0.8
              php_memory_limit: 128M
            scenarios:
              - id: normal-1
                kind: calibration
                tables: { pages: 1000, books: 300 }
                rps: { "GET /a": 5.0, "POST /m": 1.0 }
                observed: { db_cpu_utilization: 0.21 }
              - id: peak-1
                kind: target
                tables: { pages: 5000 }
                rps: { "GET /a": 15.0, "POST /m": 6.0, "GET /inc": 2 }
                observed: { db_cpu_utilization: 0.74317 }
            YAML;
    }

    public static function io(?string $scenario = null, ?string $estimate = null): RecordingIo
    {
        return new RecordingIo([self::ESTIMATE_PATH => $estimate ?? self::estimateJson(), self::SCENARIO_PATH => $scenario ?? self::scenarioYaml()]);
    }
}
