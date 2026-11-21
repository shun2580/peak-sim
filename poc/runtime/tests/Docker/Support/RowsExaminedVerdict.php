<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Support;

/**
 * The verdict of one route in `RowsExaminedMatchesServerTest`, kept apart from the database so that a plain unit test
 * can bind it with hand-written expectations.
 *
 * A route is accepted ('ok') only when all of these hold, and the first one that does not is named:
 *   1. no scan statement reads more than the fixed cap above the server's count (the maintainer's ruling: a statement over
 *      the cap fails the test, whatever the route's total looks like);
 *   2. the figure is fully accounted for by the statements the tap saw (nothing is left over);
 *   3. the total is within the limit of the server's count on the statements that are still compared (a difference of
 *      exactly the limit passes; the limit is not moved to make a route pass).
 */
final class RowsExaminedVerdict
{
    public const OK = 'ok';

    /**
     * @param int   $scanOverCap number of compared scan statements whose handler count is above the server's by more than the cap
     * @param int   $unaccounted examined figure of the run minus the sum of the tap's per-statement reads
     * @param float $diff        |app - server| / server on the compared statements (INF when the server counted 0 and the app did not)
     * @param float $limit       the share of the server's count that a route may differ by
     */
    public static function of(int $scanOverCap, int $unaccounted, float $diff, float $limit): string
    {
        if ($scanOverCap > 0) {
            return sprintf(
                '%d scan statement(s) read more than %d rows above the server (over the fixed cap)',
                $scanOverCap,
                ExcludedStatementTypes::SCAN_END_MAX,
            );
        }
        if ($unaccounted !== 0) {
            return sprintf("the figure differs from the sum of the tap's statement reads by %d", $unaccounted);
        }
        if ($diff > $limit) {
            return sprintf('differs by %s', is_infinite($diff) ? 'an unbounded share (server counted 0)' : round($diff * 100, 1) . '%');
        }

        return self::OK;
    }
}
