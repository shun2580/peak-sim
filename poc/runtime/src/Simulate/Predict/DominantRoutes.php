<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/**
 * The routes that carry most of the load (simulator-spec.md section 6.1): the top five by their share of L, influence = rps x cost
 * (the method of Scout, prior-art 5.2). Each one names its heaviest statement type and the marks of that type.
 *
 * The heaviest type is the one with the largest c_q * count + c_row * rows under the same calibration as the load. Order is fixed:
 * share descending, then the route string ascending; the first type wins a tie.
 */
final class DominantRoutes
{
    public const TOP = 5;

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return list<array<string, mixed>>
     */
    public static function top(array $routes, Coefficients $c, int $limit = self::TOP): array
    {
        $perRoute = UtilizationModel::perRoute($routes, $c, UtilizationModel::POINT);
        $total = array_sum($perRoute);
        $byRoute = [];
        foreach ($routes as $r) {
            $byRoute[(string) $r['route']] = $r;
        }
        $names = array_keys($perRoute);
        usort($names, static fn (string $a, string $b): int => [$perRoute[$b], $a] <=> [$perRoute[$a], $b]);
        $out = [];
        foreach (array_slice($names, 0, $limit) as $name) {
            $r = $byRoute[$name];
            $out[] = [
                'route' => $name,
                'rps' => (float) $r['rps'],
                'load' => $perRoute[$name],
                'share' => $total > 0.0 ? $perRoute[$name] / $total : 0.0,
                'marked' => ($r['marked'] ?? false) === true,
                'heaviest_statement' => self::heaviest((array) ($r['types'] ?? []), $c),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $types
     *
     * @return array<string, mixed>|null
     */
    private static function heaviest(array $types, Coefficients $c): ?array
    {
        $best = null;
        $bestWeight = -1.0;
        foreach ($types as $t) {
            $weight = $c->cQ * (float) ($t['count'] ?? 0.0) + $c->cRow * (float) ($t['rows'] ?? 0.0);
            if ($weight > $bestWeight) {
                $bestWeight = $weight;
                $best = $t;
            }
        }
        if ($best === null) {
            return null;
        }

        return [
            'id' => (string) ($best['id'] ?? ''),
            'pattern' => (string) ($best['pattern'] ?? ''),
            'marks' => [
                'plan_changes_at_scale' => ($best['marks']['plan_changes_at_scale'] ?? false) === true,
                'plan_unstable' => ($best['marks']['plan_unstable'] ?? false) === true,
            ],
            'rows' => (float) ($best['rows'] ?? 0.0),
            'count' => (float) ($best['count'] ?? 0.0),
        ];
    }
}
