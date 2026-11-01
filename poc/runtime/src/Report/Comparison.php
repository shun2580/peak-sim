<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Report;

use PeakSim\Runtime\Judge\Verdict;

/**
 * Sets a runtime verdict against what is already known about a route. Nothing here is adjusted to make two sides
 * agree: a disagreement is a finding and is reported as it is.
 *
 * Against the static analysis (query axis only; the memory figure is a reference and is not compared):
 *   agree            the static analysis is determinate and the measurement says the same
 *   runtime_only     the static analysis is unknown and the measurement decided
 *   static_stronger  the static analysis has a lower bound and the measurement came out below it
 *                    (a suspected over-estimate of the bound)
 *   conflict         the static analysis is determinate and the measurement says something else. Highest priority.
 *   not_comparable   the measurement decided nothing (inconclusive, no information, not measured), or it was taken
 *                    with the authorization gate bypassed and would otherwise have been a conflict or a stronger
 *                    static result: such a number is a reference only
 *
 * Against the fixture app's expectations (fixtures/expected.json): match, mismatch, inconclusive, no_info,
 * not_measured, and for an expectation that is itself unknown: unknown_filled / unknown_open.
 */
final class Comparison
{
    private const RANK = ['O(1)' => 0, 'O(N)' => 1, 'O(N*M)' => 2];

    /**
     * @param array{growth?: ?string, lower_bound?: ?string, confidence?: ?string} $staticQuery
     */
    public static function relate(array $staticQuery, Verdict $runtime): string
    {
        return self::compare($staticQuery, $runtime)['relation'];
    }

    /**
     * @param array{growth?: ?string, lower_bound?: ?string, confidence?: ?string} $staticQuery
     *
     * @return array{relation: string, note: ?string}
     */
    public static function compare(array $staticQuery, Verdict $runtime): array
    {
        $measured = $runtime->growth();
        if ($measured === null) {
            return ['relation' => 'not_comparable', 'note' => null];
        }

        $growth = $staticQuery['growth'] ?? null;
        $lowerBound = $staticQuery['lower_bound'] ?? null;

        if (isset(self::RANK[(string) $growth])) {
            $relation = $growth === $measured ? 'agree' : 'conflict';
        } elseif ($lowerBound !== null && isset(self::RANK[$lowerBound]) && self::RANK[$measured] < self::RANK[$lowerBound]) {
            $relation = 'static_stronger';
        } else {
            $relation = 'runtime_only';
        }

        if ($runtime->gateBypass) {
            if ($relation === 'conflict' || $relation === 'static_stronger') {
                return ['relation' => 'not_comparable', 'note' => sprintf('gate bypassed: reference only (would be %s)', $relation)];
            }

            return ['relation' => $relation, 'note' => 'gate bypassed: reference only'];
        }

        return ['relation' => $relation, 'note' => null];
    }

    public static function againstExpected(?string $expectedQuery, Verdict $runtime): string
    {
        if ($expectedQuery === null || $expectedQuery === 'unknown') {
            return $runtime->growth() !== null ? 'unknown_filled' : 'unknown_open';
        }

        return match ($runtime->kind) {
            Verdict::NO_INFO => 'no_info',
            Verdict::NOT_MEASURED => 'not_measured',
            Verdict::INCONCLUSIVE => 'inconclusive',
            default => $runtime->growth() === $expectedQuery ? 'match' : 'mismatch',
        };
    }

    /**
     * @return list<array<string, mixed>> the `routes` of the expectations file
     */
    public static function loadExpected(string $path): array
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new \InvalidArgumentException('cannot read the expectations file');
        }
        $data = json_decode($json, true);
        $routes = is_array($data) ? ($data['routes'] ?? null) : null;
        if (! is_array($routes) || $routes === [] || ! isset($routes[0]['route'])) {
            throw new \InvalidArgumentException('not an expectations file: no `routes` with a `route` in each');
        }

        return array_values($routes);
    }

    /**
     * One row per expected route, in the expectations' order.
     *
     * @param list<array<string, mixed>> $expected
     * @param array<string, array{growth?: ?string, lower_bound?: ?string, confidence?: ?string}> $staticByRoute keyed by the route string
     * @param array<string, Verdict> $verdictByRoute
     *
     * @return list<array<string, mixed>>
     */
    public static function table(array $expected, array $staticByRoute, array $verdictByRoute): array
    {
        $rows = [];
        foreach ($expected as $e) {
            $route = (string) $e['route'];
            $static = $staticByRoute[$route] ?? [];
            $verdict = $verdictByRoute[$route] ?? null;
            $ran = $verdict !== null;
            $verdict ??= Verdict::notMeasured('route_not_run');
            $cmp = self::compare($static, $verdict);

            $rows[] = [
                'route' => $route,
                'group' => $e['group'] ?? null,
                'expected' => $e['query'] ?? null,
                'static' => $static['growth'] ?? null,
                'static_lower_bound' => $static['lower_bound'] ?? null,
                'static_confidence' => $static['confidence'] ?? null,
                'runtime' => $verdict->kind,
                'runtime_growth' => $verdict->growth(),
                'scope' => $verdict->scope,
                'gate_bypass' => $verdict->gateBypass,
                'relation' => $cmp['relation'],
                'vs_expected' => self::againstExpected($e['query'] ?? null, $verdict),
                'note' => $cmp['note'],
                'reason' => $ran ? $verdict->reason : 'route_not_run',
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows from table()
     * @param string|null $profile the app profile the run used, or null for none
     */
    public static function toMarkdown(array $rows, ?string $profile = null): string
    {
        $disagree = array_values(array_filter($rows, static fn (array $r): bool => $r['relation'] === 'conflict' || $r['vs_expected'] === 'mismatch'));

        $md = "# Static analysis, runtime measurement and the fixture expectations\n\n";
        $md .= sprintf("profile: %s\n\n", $profile ?? 'none');
        $md .= "A runtime result is what was measured on this input, along the path that was taken (its scope is in the table). ";
        $md .= "Nothing below is adjusted to the expectation or to the measurement: a difference is not fitted, it is a finding.\n\n";
        $md .= "## Disagreements\n\n";
        if ($disagree === []) {
            $md .= "No disagreements: no route where the static analysis was determinate and the measurement said otherwise, and none that differs from its expectation.\n\n";
        } else {
            foreach ($disagree as $r) {
                $md .= sprintf(
                    "- `%s`: expected %s, static %s, measured %s (%s)%s\n",
                    $r['route'],
                    $r['expected'] ?? 'unknown',
                    $r['static'] ?? 'unknown',
                    $r['runtime_growth'] ?? $r['runtime'],
                    $r['relation'] === 'conflict' ? 'conflict with the static analysis' : 'differs from the expectation',
                    $r['scope'] === null ? '' : sprintf(', scope %s', $r['scope']),
                );
            }
            $md .= "\n";
        }

        $md .= "## All routes\n\n";
        $md .= "| route | group | expected | static | runtime | scope | static vs runtime | runtime vs expected | note |\n";
        $md .= "| --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($rows as $r) {
            $md .= sprintf(
                "| %s | %s | %s | %s | %s | %s | %s | %s | %s |\n",
                self::cell($r['route']),
                self::cell($r['group']),
                self::cell($r['expected']),
                self::cell($r['static']),
                self::cell($r['runtime_growth'] ?? $r['runtime']),
                self::cell($r['scope']),
                self::cell($r['relation']),
                self::cell($r['vs_expected']),
                self::cell($r['note'] ?? $r['reason']),
            );
        }

        return $md;
    }

    private static function cell(mixed $value): string
    {
        return $value === null || $value === '' ? '-' : str_replace('|', '\\|', (string) $value);
    }
}
