<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/**
 * The placeholder side of the mark `app_limit_risk` (simulator-spec.md section 6.4): a route that may send the database one statement with more bound
 * values than the database accepts. {@see AppLimitRisk} is the side of the execution time and {@see AppMemoryRisk} the side of the memory; the three are
 * judged apart, each with its own block in the output, so that the output says which limit a route was marked for.
 *
 * The limit is an input of the run (`resources.db_placeholder_limit`, the most values one prepared statement may carry: 65,535 for MySQL); there is no default and
 * the tool does not know the database. With none given nothing here is called, and the output has no trace of it.
 *
 * The figure is the number of values one statement of the route carries at the scenario's table size. The estimate (made with `bin/estimate --with-placeholders`)
 * gives, for each statement type, how many values the capture's representative statement was sent with (`values`), how many of them no list holds (`fixed`)
 * and the scale it was taken at (`scale`). The rule is {@see self::valuesAt}: what a list holds grows in proportion to the table size, the rest stays.
 * A route is marked when the largest figure over its statement types is above the limit.
 *
 * The rule is read from the lab's own records and nothing else: `docs/research/lab-data/diagnosis/app-placeholder-risk/` gives the captures, the way it is
 * worked out, and what it says on the counts already seen (one statement of the books page: 4 + 0.6 N values, 6,004 at 10,000 rows and 18,004 at 30,000, and
 * the limit reached between 100,000 and 200,000 rows). Proportional growth is an assumption about what a list holds (a list built from rows of the table
 * that grows), not something the capture can show for a type it saw at one scale; it leans to marking too much, since a list that does not grow is scaled up as
 * well, and a statement that is refused without a mark is the failure.
 *
 * A statement type that the estimate gives no figure for (an estimate made without the flag, or a type with no representative) is not counted as having
 * few values: a route with no figure at all is not judged and is listed apart with the reason, and a route with some figures is judged on those and says
 * how many of its statement types had none.
 *
 * The mark does not change a utilisation or a limit rps. The limit rps of a scenario counts a marked route as one that runs to the end
 * ({@see AppLimitRisk::LIMIT_RPS_NOTE}, simulator-spec.md R1a).
 */
final class AppPlaceholderRisk
{
    public const MARKED = 'marked';

    public const NOT_MARKED = 'not_marked';

    /** The route has no cost, or none of its statement types has a figure, so the mark could not be judged. */
    public const NOT_EVALUATED = 'not_evaluated';

    /**
     * The number of values one statement carries at the table size `n`, from a type's `placeholders` (`scale`, `values`, `fixed`): `fixed` stays and what a
     * list holds (`values - fixed`) is scaled in proportion to `n / scale`, rounded up.
     *
     * @param array{scale: int|float, values: int|float, fixed: int|float} $p
     */
    public static function valuesAt(array $p, int|float $n): float
    {
        $scale = (float) $p['scale'];
        $values = (float) $p['values'];
        $fixed = min((float) $p['fixed'], $values);
        if (!($scale > 0.0)) {
            throw new \InvalidArgumentException('the scale of a placeholder figure must be above 0');
        }

        return $fixed + ceil(($values - $fixed) * (float) $n / $scale);
    }

    /**
     * @param list<array<string, mixed>> $routes the routes of one scenario as the loaded input gives them (rps is not used)
     * @param int                        $limit  the most values one statement may carry, above 0
     *
     * @return array{
     *     limit_kind: string,
     *     limit_values: int,
     *     rule: string,
     *     routes: list<array{route: string, status: string, values: float|null, statement: string|null, share_of_limit: float|null, types_without_figure: int, reason: string|null}>,
     *     marked_routes: list<string>,
     *     limit_rps_note: string|null
     * }
     */
    public static function evaluate(array $routes, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('the placeholder limit must be above 0 values');
        }
        $rows = [];
        $marked = [];
        foreach ($routes as $r) {
            $name = (string) $r['route'];
            if (($r['cost'] ?? null) !== 'ok') {
                $rows[] = ['route' => $name, 'status' => self::NOT_EVALUATED, 'values' => null, 'statement' => null, 'share_of_limit' => null, 'types_without_figure' => 0, 'reason' => 'no_cost'];

                continue;
            }
            $best = null;
            $bestId = null;
            $without = 0;
            foreach ((array) ($r['types'] ?? []) as $t) {
                $p = $t['placeholders'] ?? null;
                if (!is_array($p) || !isset($p['scale'], $p['values'], $p['fixed'], $p['at'])) {
                    ++$without;

                    continue;
                }
                $v = self::valuesAt($p, $p['at']);
                if ($best === null || $v > $best) {
                    $best = $v;
                    $bestId = (string) ($t['id'] ?? '');
                }
            }
            if ($best === null) {
                $rows[] = ['route' => $name, 'status' => self::NOT_EVALUATED, 'values' => null, 'statement' => null, 'share_of_limit' => null, 'types_without_figure' => $without, 'reason' => 'no_placeholder_figure'];

                continue;
            }
            $isMarked = $best > $limit;
            if ($isMarked) {
                $marked[] = $name;
            }
            $rows[] = [
                'route' => $name,
                'status' => $isMarked ? self::MARKED : self::NOT_MARKED,
                'values' => $best,
                'statement' => $bestId,
                'share_of_limit' => $best / $limit,
                'types_without_figure' => $without,
                'reason' => null,
            ];
        }

        return [
            'limit_kind' => 'placeholders',
            'limit_values' => $limit,
            'rule' => 'marked when the largest number of values one statement of the route carries at the scenario\'s table size (fixed + what a list holds x table size / the scale of the capture) > limit_values',
            'routes' => $rows,
            'marked_routes' => $marked,
            'limit_rps_note' => $marked === [] ? null : AppLimitRisk::LIMIT_RPS_NOTE,
        ];
    }
}
