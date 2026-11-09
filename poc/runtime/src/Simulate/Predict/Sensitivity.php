<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/**
 * The inputs that move the result most (simulator-spec.md section 6.4): each input is moved up by 10% on its own, and the change of the
 * limit multiplier (point value, point calibration) is read. The five largest changes, in order.
 *
 * The inputs are the count of each table the scenario gives and the rps of each route. A table's count goes to ceil(1.1 x count) with
 * integer arithmetic, and the routes are evaluated again at the new count by $evaluate (the costs depend on the counts). When no
 * $evaluate is given the tables are listed as not evaluated, never as "no change".
 *
 * The change is measured on the reported value (steps of 0.1; "not within 10x" counts as step 101). The grid hides small moves, so the
 * unrounded multiplier (threshold / utilisation now) breaks ties, then the name of the input. The order is the same for the same input.
 */
final class Sensitivity
{
    public const TOP = 5;

    public const CAP_STEP = LimitSearch::STEPS + 1;

    /**
     * @param array<string, int>                                                                       $tables
     * @param list<array<string, mixed>>                                                              $routes   the routes with rps above 0
     * @param (callable(array<string, int>, array<string, float>): list<array<string, mixed>>)|null    $evaluate
     *
     * @return array{top: list<array<string, mixed>>, not_evaluated: list<string>}
     */
    public static function top(array $tables, array $routes, Coefficients $c, float $cores, float $threshold, ?callable $evaluate, int $limit = self::TOP): array
    {
        $base = self::read($routes, $c, $cores, $threshold);
        $rows = [];
        $notEvaluated = [];

        $rpsByRoute = [];
        foreach ($routes as $r) {
            $rpsByRoute[(string) $r['route']] = (float) $r['rps'];
        }
        foreach ($routes as $i => $r) {
            $moved = $routes;
            $moved[$i]['rps'] = (float) $r['rps'] * 1.1;
            $rows[] = self::row('rps ' . $r['route'], (float) $r['rps'], (float) $r['rps'] * 1.1, $base, self::read($moved, $c, $cores, $threshold));
        }
        ksort($tables);
        foreach ($tables as $name => $count) {
            if ($evaluate === null) {
                $notEvaluated[] = 'table ' . $name;
                continue;
            }
            $next = intdiv($count * 11 + 9, 10);
            $movedTables = $tables;
            $movedTables[$name] = $next;
            $rows[] = self::row('table ' . $name, (float) $count, (float) $next, $base, self::read($evaluate($movedTables, $rpsByRoute), $c, $cores, $threshold));
        }

        $rows = array_values(array_filter($rows, static fn (array $x): bool => $x['change'] !== 0.0 || $x['change_unrounded'] !== 0.0));
        usort($rows, static fn (array $a, array $b): int => [abs($b['change']), abs($b['change_unrounded']), $a['input']] <=> [abs($a['change']), abs($a['change_unrounded']), $b['input']]);

        return ['top' => array_slice($rows, 0, $limit), 'not_evaluated' => $notEvaluated];
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return array{step: int, exact: float|null}
     */
    private static function read(array $routes, Coefficients $c, float $cores, float $threshold): array
    {
        $now = UtilizationModel::load($routes, $c, UtilizationModel::POINT) / $cores;
        $found = LimitSearch::findProportional($now, $threshold);

        return ['step' => $found['k'] ?? self::CAP_STEP, 'exact' => $now > 0.0 ? $threshold / $now : null];
    }

    /**
     * @param array{step: int, exact: float|null} $base
     * @param array{step: int, exact: float|null} $after
     *
     * @return array<string, mixed>
     */
    private static function row(string $input, float $from, float $to, array $base, array $after): array
    {
        return [
            'input' => $input,
            'from' => $from,
            'to' => $to,
            'limit_before' => $base['step'] === self::CAP_STEP ? null : (float) ($base['step'] / LimitSearch::PER_UNIT),
            'limit_after' => $after['step'] === self::CAP_STEP ? null : (float) ($after['step'] / LimitSearch::PER_UNIT),
            'limit_unrounded_before' => $base['exact'],
            'limit_unrounded_after' => $after['exact'],
            'change' => (float) (($after['step'] - $base['step']) / LimitSearch::PER_UNIT),
            'change_unrounded' => $base['exact'] !== null && $after['exact'] !== null ? $after['exact'] - $base['exact'] : 0.0,
        ];
    }
}
