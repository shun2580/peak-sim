<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Ladder;

use PeakSim\Runtime\Simulate\Load\EstimateFormulas;

/**
 * Puts the safe side of each target scenario on the marks that fit the scenario's own count of rows (simulator-spec.md sections 4.4 and 6.3).
 *
 * Which statements are marked (`plan_changes_at_scale`, `plan_unstable`), and so which routes have an upper side, depends on the size the
 * EXPLAIN was made at: at 10,000 rows 3 routes are marked, at 30,000 rows 4, at 100,000 rows 3 others. The point is one formula (the lab's
 * estimate, made once); the marks are the EXPLAIN's at the size of the scenario. So the ladder takes the estimate it predicts from (its
 * point, and its marks at its own size) and, beside it, estimates made at other sizes whose marks it may use.
 *
 * For a target scenario whose routes read tables of `n` rows, the marks come from the estimate made at `n` rows when there is one (a given
 * safe-side estimate, or the main estimate if it was made at `n`). Only the marks and the upper side are taken from it: the point of every
 * route (queries and rows) stays the main estimate's, and the two points must be the same number (they are the same formula; if they are not,
 * this stops). A count that no estimate was made at keeps the main estimate's marks, and says so ({@see self::SOURCE_MAIN_OTHER_COUNT}).
 *
 * It changes nothing but target scenarios, and nothing in a calibration scenario: the calibration is made on the points only.
 */
final class SafeSideEstimates
{
    /** The marks are those of an estimate made at the scenario's own count. */
    public const SOURCE_AT_COUNT = 'estimate_at_count';

    /** The main estimate was made at another count than the scenario's, and no estimate was given for the scenario's count. */
    public const SOURCE_MAIN_OTHER_COUNT = 'main_estimate_other_count';

    /** The fields of a route that the marks decide: the upper side, the width and the mark itself. */
    private const MARK_FIELDS = ['marked', 'rows_upper', 'queries_low', 'queries_high', 'rows_low', 'rows_high', 'types'];

    /**
     * @param list<array{estimate: EstimateFormulas, commit: string|null, commit_source: string|null, sha256: string}> $others the safe-side estimates beside the main one
     * @param array{commit: string|null, commit_source: string|null, sha256: string}                                      $main
     */
    public function __construct(
        private readonly EstimateFormulas $mainEstimate,
        private readonly array $main,
        private readonly array $others,
    ) {
        $seen = [];
        foreach ($others as $o) {
            $at = $o['estimate']->at;
            if ($at === $mainEstimate->at || isset($seen[$at])) {
                throw new \InvalidArgumentException(sprintf('two estimates were given for the count %d (the marks of one count come from one estimate)', $at));
            }
            $seen[$at] = true;
        }
    }

    /**
     * @param array<string, mixed> $loaded the loaded input (LoadedInput::toArray())
     *
     * @return array<string, mixed> the same, with the marks of every target scenario put on its own count, and `safe_side` on each target scenario
     *
     * @throws \InvalidArgumentException a point that differs between the main estimate and the one the marks come from
     */
    public function apply(array $loaded): array
    {
        $scenarios = [];
        foreach ((array) $loaded['scenarios'] as $s) {
            $scenarios[] = ($s['kind'] ?? null) === 'target' ? $this->scenario($s) : $s;
        }
        $loaded['scenarios'] = $scenarios;

        return $loaded;
    }

    /** The estimates the marks may come from, as the output records them (no path). @return list<array<string, mixed>> */
    public function sources(): array
    {
        $out = [self::describe($this->mainEstimate, $this->main, 'main')];
        foreach ($this->others as $o) {
            $out[] = self::describe($o['estimate'], $o, 'safe_side');
        }
        usort($out, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $out;
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private function scenario(array $s): array
    {
        $byCount = [];
        $routes = [];
        foreach ((array) $s['routes'] as $r) {
            $n = $r['n'] ?? null;
            if (!is_int($n) || ($r['cost'] ?? null) !== 'ok') {
                $routes[] = $r;

                continue;
            }
            [$estimate, $source] = $this->estimateFor($n);
            if ($estimate !== $this->mainEstimate) {
                $r = self::withMarksOf($r, $estimate, (string) $s['id']);
            }
            $byCount[$n]['source'] = $source;
            $byCount[$n]['at'] = $estimate->at;
            $byCount[$n]['routes_marked'][] = ($r['marked'] ?? false) === true ? (string) $r['route'] : null;
            $routes[] = $r;
        }
        $s['routes'] = $routes;

        $sides = [];
        ksort($byCount);
        foreach ($byCount as $n => $info) {
            $sides[] = [
                'count' => $n,
                'source' => $info['source'],
                'estimate_at' => $info['at'],
                'marked_routes' => array_values(array_filter(array_unique($info['routes_marked']))),
            ];
        }
        $s['safe_side'] = $sides;

        return $s;
    }

    /** @return array{0: EstimateFormulas, 1: string} */
    private function estimateFor(int $n): array
    {
        foreach ($this->others as $o) {
            if ($o['estimate']->at === $n) {
                return [$o['estimate'], self::SOURCE_AT_COUNT];
            }
        }

        return [$this->mainEstimate, $this->mainEstimate->at === $n ? self::SOURCE_AT_COUNT : self::SOURCE_MAIN_OTHER_COUNT];
    }

    /**
     * The target scenarios that read a count of rows no estimate was made at (the marks of such a scenario would be another count's).
     *
     * @param array<string, mixed> $loaded
     *
     * @return array<string, list<int>> scenario id => the counts with no estimate
     */
    public function missingCounts(array $loaded): array
    {
        $missing = [];
        foreach ((array) $loaded['scenarios'] as $s) {
            if (($s['kind'] ?? null) !== 'target') {
                continue;
            }
            foreach ((array) $s['routes'] as $r) {
                $n = $r['n'] ?? null;
                if (is_int($n) && ($r['cost'] ?? null) === 'ok' && $this->estimateFor($n)[1] !== self::SOURCE_AT_COUNT) {
                    $missing[(string) $s['id']][$n] = $n;
                }
            }
        }

        return array_map(static fn (array $counts): array => array_values($counts), $missing);
    }

    /**
     * @param array<string, mixed> $route
     *
     * @return array<string, mixed>
     */
    private static function withMarksOf(array $route, EstimateFormulas $from, string $scenarioId): array
    {
        $n = (int) $route['n'];
        $name = (string) $route['route'];
        $evaluated = $from->evaluate($name, $n);
        if ($evaluated['cost'] !== 'ok') {
            throw new \InvalidArgumentException(sprintf('the estimate made at %d rows has no cost for the route "%s" that the main estimate costs (scenario %s)', $from->at, $name, $scenarioId));
        }
        foreach (['queries', 'rows'] as $point) {
            $a = (float) $route[$point];
            $b = (float) $evaluated[$point];
            if (abs($a - $b) > 1e-9 * max(1.0, abs($a))) {
                throw new \InvalidArgumentException(sprintf(
                    'the point differs between the main estimate and the estimate made at %d rows for the route "%s" (%s %s against %s): the point is one formula, so the marks may be taken from the other estimate only when the points agree',
                    $from->at,
                    $name,
                    $point,
                    (string) $a,
                    (string) $b,
                ));
            }
        }
        foreach (self::MARK_FIELDS as $field) {
            $route[$field] = $evaluated[$field];
        }
        $route['sources'] = array_merge((array) $route['sources'], ['rows_upper' => $evaluated['sources']['rows_upper']]);

        return $route;
    }

    /**
     * @param array{commit: string|null, commit_source: string|null, sha256: string} $info
     *
     * @return array<string, mixed>
     */
    private static function describe(EstimateFormulas $estimate, array $info, string $role): array
    {
        return [
            'role' => $role,
            'at' => $estimate->at,
            'commit' => $info['commit'],
            'commit_source' => $info['commit'] === null ? null : $info['commit_source'],
            'sha256' => $info['sha256'],
        ];
    }
}
