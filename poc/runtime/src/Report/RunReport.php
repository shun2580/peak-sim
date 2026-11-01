<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Report;

use PeakSim\Runtime\Judge\Verdict;
use PeakSim\Runtime\Route\RouteSpec;
use PeakSim\Runtime\Run\Attempt;

/**
 * The record of a run, one row per route: what was tried, what class of outcome it had, the verdict with its
 * scope, and how it stands against the static analysis. Deterministic: rows are sorted by route and method, no
 * times and no absolute paths.
 *
 * Which attempts decide a verdict: the COLD attempt (empty cache, fresh login) of each scale, with class `ok`.
 * Attempts on the axis `N` (or `global`) form the main sweep; attempts on the axis `M` form the second sweep over
 * the children per parent row. Warm-up and warm attempts are kept in the attempt log and are not used here.
 */
final class RunReport
{
    private const VERDICTS = [Verdict::MEASURED_O1, Verdict::MEASURED_ON, Verdict::MEASURED_ONM, Verdict::INCONCLUSIVE, Verdict::NO_INFO, Verdict::NOT_MEASURED];

    private const RELATIONS = ['agree', 'runtime_only', 'static_stronger', 'conflict', 'not_comparable'];

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /**
     * @param list<Attempt> $attempts every attempt of this route, any scale and axis
     * @param string|null $failClass why the route could not be (fully) measured, from the fixed vocabulary
     *
     * @return array<string, mixed>
     */
    public static function rowFor(RouteSpec $spec, array $attempts, ?string $failClass = null, ?string $failDetail = null): array
    {
        $main = [];
        $second = [];
        $bytes = [];
        $touched = [];
        $bypass = false;
        $scope = self::scopeOf($spec);
        $scaled = $scope === 'global' ? null : self::scaledTables($spec);

        foreach ($attempts as $a) {
            if ($a->attempt !== Attempt::COLD || $a->class !== 'ok' || $a->queries === null) {
                continue;
            }
            $axis = strtolower($a->axis);
            if ($axis === 'm') {
                $second[$a->scale] = $a->queries;

                continue;
            }
            $main[$a->scale] = $a->queries;
            $bytes[$a->scale] = $a->bytes;
            $bypass = $bypass || $a->gateBypass;
            foreach ($a->tables as $table) {
                $touched[] = self::bare($table);
            }
        }
        ksort($main);
        ksort($second);

        if ($main === []) {
            $verdict = Verdict::notMeasured($failClass ?? 'no_attempts', null, array_reduce($attempts, static fn (bool $c, Attempt $a): bool => $c || $a->gateBypass, false));
        } else {
            $untouched = $scaled === null ? $touched === [] : array_intersect($scaled, $touched) === [];
            $distinct = array_unique($bytes, SORT_REGULAR);
            $suspectEmpty = count($bytes) >= 2 && ! in_array(null, $bytes, true) && count($distinct) === 1;
            $scales = array_keys($main);
            $values = array_values($main);

            $verdict = $second !== [] && $scope !== 'global'
                ? Verdict::fromSweeps($scope, $scales, $values, array_keys($second), array_values($second), $untouched, $suspectEmpty, $bypass)
                : Verdict::fromSweep($scope, $scales, $values, $untouched, $suspectEmpty, $bypass);
        }

        $staticQuery = $spec->static['query'] ?? [];
        $cmp = Comparison::compare($staticQuery, $verdict);
        $lastClass = $attempts === [] ? 'no_attempts' : $attempts[array_key_last($attempts)]->class;
        $class = $failClass ?? ($main !== [] ? 'ok' : ($lastClass !== 'ok' ? $lastClass : 'no_attempts'));

        return [
            'route' => $spec->uri,
            'method' => $spec->method,
            'tried' => $attempts !== [],
            'class' => $class,
            'reason_detail' => $failDetail,
            'reason' => $verdict->reason,
            'scales' => $verdict->scales,
            'values' => $verdict->values,
            'm_scales' => $verdict->mScales,
            'm_values' => $verdict->mValues,
            'slope' => $verdict->slope === null ? null : round($verdict->slope, 4),
            'slope_m' => $verdict->slopeM === null ? null : round($verdict->slopeM, 4),
            'verdict' => $verdict->kind,
            'scope' => $verdict->scope,
            'gate_bypass' => $verdict->gateBypass,
            'static' => [
                'growth' => $staticQuery['growth'] ?? null,
                'lower_bound' => $staticQuery['lower_bound'] ?? null,
                'confidence' => $staticQuery['confidence'] ?? null,
            ],
            'relation' => $cmp['relation'],
            'note' => $cmp['note'],
            'statement' => $verdict->statement(),
        ];
    }

    /** @param array<string, mixed> $row */
    public function add(array $row): void
    {
        $this->rows[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $rows = $this->rows;
        usort($rows, static fn (array $a, array $b): int => [$a['route'], $a['method']] <=> [$b['route'], $b['method']]);

        return $rows;
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $classes = [];
        $verdicts = array_fill_keys(self::VERDICTS, 0);
        $relations = array_fill_keys(self::RELATIONS, 0);
        $conflicts = [];
        $stronger = [];
        $tried = 0;

        foreach ($this->rows() as $r) {
            $classes[$r['class']] = ($classes[$r['class']] ?? 0) + 1;
            ++$verdicts[$r['verdict']];
            ++$relations[$r['relation']];
            $tried += $r['tried'] ? 1 : 0;
            if ($r['relation'] === 'conflict') {
                $conflicts[] = $r['method'] . ' ' . $r['route'];
            }
            if ($r['relation'] === 'static_stronger') {
                $stronger[] = $r['method'] . ' ' . $r['route'];
            }
        }
        ksort($classes);

        return [
            'routes' => count($this->rows),
            'tried' => $tried,
            'classes' => $classes,
            'verdicts' => $verdicts,
            'relations' => $relations,
            'conflicts' => $conflicts,
            'static_stronger' => $stronger,
        ];
    }

    public function toJsonLines(): string
    {
        $out = '';
        foreach ($this->rows() as $row) {
            $out .= json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }

        return $out;
    }

    public function toMarkdown(): string
    {
        $s = $this->summary();
        $md = "# Runtime measurement run\n\n";
        $md .= "Every verdict here was measured on this input, along the path that was taken, in the scope named beside it. ";
        $md .= "A flat count on a path that never read the scaled tables, or that returned an empty response, is `no_info`, not O(1). ";
        $md .= "Where the static analysis and the measurement disagree, both are shown as they are, and a difference is not fitted to either side.\n\n";

        $md .= "## Conflicts\n\n";
        if ($s['conflicts'] === []) {
            $md .= "No conflicts: no route where the static analysis was determinate and the measurement said otherwise.\n\n";
        } else {
            $md .= "The static analysis was determinate and the measurement says otherwise. Reported first, as measured:\n\n";
            foreach ($this->rows() as $r) {
                if ($r['relation'] === 'conflict') {
                    $md .= sprintf(
                        "- `%s %s`: static %s, runtime %s (scope %s; counts %s at scales %s)\n",
                        $r['method'],
                        $r['route'],
                        $r['static']['growth'] ?? 'unknown',
                        $r['verdict'],
                        $r['scope'],
                        implode(', ', $r['values']),
                        implode(', ', $r['scales']),
                    );
                }
            }
            $md .= "\n";
        }

        $md .= "## Summary\n\n";
        $md .= sprintf("Routes: %d. Tried: %d.\n\n", $s['routes'], $s['tried']);
        $md .= "| outcome class | routes |\n| --- | ---: |\n";
        foreach ($s['classes'] as $class => $n) {
            $md .= sprintf("| %s | %d |\n", $class, $n);
        }
        $md .= "\n| verdict | routes |\n| --- | ---: |\n";
        foreach ($s['verdicts'] as $verdict => $n) {
            $md .= sprintf("| %s | %d |\n", $verdict, $n);
        }
        $md .= "\n| against the static analysis | routes |\n| --- | ---: |\n";
        foreach ($s['relations'] as $relation => $n) {
            $md .= sprintf("| %s | %d |\n", $relation, $n);
        }

        $md .= "\n## Routes\n\n";
        $md .= "| route | method | outcome | verdict | scope | slope | static | against static | note |\n";
        $md .= "| --- | --- | --- | --- | --- | ---: | --- | --- | --- |\n";
        foreach ($this->rows() as $r) {
            $md .= sprintf(
                "| %s | %s | %s | %s | %s | %s | %s | %s | %s |\n",
                self::cell($r['route']),
                $r['method'],
                $r['class'],
                $r['verdict'],
                self::cell($r['scope']),
                $r['slope'] === null ? '-' : (string) $r['slope'],
                self::cell($r['static']['growth'] ?? ($r['static']['lower_bound'] === null ? null : '>= ' . $r['static']['lower_bound'])),
                $r['relation'],
                self::cell($r['note'] ?? $r['reason_detail'] ?? $r['reason']),
            );
        }

        return $md;
    }

    private static function scopeOf(RouteSpec $spec): string
    {
        $table = $spec->static['n_table'] ?? null;

        return is_string($table) && preg_match('/^[A-Za-z0-9_.]+$/', $table) === 1 ? 'targeted:' . $table : 'global';
    }

    /** @return list<string> */
    private static function scaledTables(RouteSpec $spec): array
    {
        $tables = array_merge(
            [$spec->static['n_table'] ?? null, $spec->static['m_table'] ?? null],
            $spec->static['n_table_alternatives'] ?? [],
        );

        return array_values(array_unique(array_map(static fn (string $t): string => self::bare($t), array_filter($tables, 'is_string'))));
    }

    private static function bare(string $table): string
    {
        $dot = strrpos($table, '.');

        return strtolower(trim($dot === false ? $table : substr($table, $dot + 1), '`"'));
    }

    private static function cell(mixed $value): string
    {
        return $value === null || $value === '' ? '-' : str_replace('|', '\\|', (string) $value);
    }
}
