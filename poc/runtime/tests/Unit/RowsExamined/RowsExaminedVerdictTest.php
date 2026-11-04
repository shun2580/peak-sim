<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\RowsExamined;

use PeakSim\Runtime\Tests\Docker\Support\RowsExaminedVerdict;
use PHPUnit\Framework\TestCase;

/**
 * Binds the verdict of one route of the rows-examined check with expectations written by hand. No Docker, no database:
 * the rule that "a scan statement over the fixed cap fails the test" must not depend on how large the route's total is.
 */
final class RowsExaminedVerdictTest extends TestCase
{
    private const LIMIT = 0.10;

    public function testARouteWithNothingOverTheCapAndNoDifferenceIsOk(): void
    {
        self::assertSame('ok', RowsExaminedVerdict::of(0, 0, 0.0, self::LIMIT));
    }

    public function testOneScanStatementOverTheCapFailsEvenWhenTheTotalDiffersByNothing(): void
    {
        $verdict = RowsExaminedVerdict::of(1, 0, 0.0, self::LIMIT);

        self::assertSame('1 scan statement(s) read more than 3 rows above the server (over the fixed cap)', $verdict);
    }

    public function testOneScanStatementOverTheCapFailsEvenWhenTheTotalIsFarInsideTheLimit(): void
    {
        // a route of 1,000 rows where one statement reads 5 above the server: 0.5% of the total, yet over the cap
        self::assertNotSame('ok', RowsExaminedVerdict::of(1, 0, 0.005, self::LIMIT));
        self::assertNotSame('ok', RowsExaminedVerdict::of(2, 0, 0.0, self::LIMIT));
    }

    public function testADifferenceOfElevenPercentFails(): void
    {
        self::assertSame('differs by 11%', RowsExaminedVerdict::of(0, 0, 0.11, self::LIMIT));
    }

    public function testADifferenceOfExactlyTheLimitIsStillOk(): void
    {
        self::assertSame('ok', RowsExaminedVerdict::of(0, 0, 0.10, self::LIMIT));
        self::assertNotSame('ok', RowsExaminedVerdict::of(0, 0, 0.1001, self::LIMIT));
    }

    public function testAnUnboundedDifferenceFails(): void
    {
        self::assertSame('differs by an unbounded share (server counted 0)', RowsExaminedVerdict::of(0, 0, INF, self::LIMIT));
    }

    public function testAFigureThatTheTapDoesNotFullyAccountForFails(): void
    {
        self::assertSame("the figure differs from the sum of the tap's statement reads by 1", RowsExaminedVerdict::of(0, 1, 0.0, self::LIMIT));
        self::assertSame("the figure differs from the sum of the tap's statement reads by -2", RowsExaminedVerdict::of(0, -2, 0.0, self::LIMIT));
    }

    public function testTheOverTheCapReasonIsNamedBeforeTheOthers(): void
    {
        self::assertStringContainsString('over the fixed cap', RowsExaminedVerdict::of(1, 5, 0.5, self::LIMIT));
    }
}
