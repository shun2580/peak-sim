<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\LimitSearch;
use PHPUnit\Framework\TestCase;

/** The limit multiplier is the first whole step k/10 (k = 1..100) whose utilisation is strictly over the threshold. */
final class LimitSearchTest extends TestCase
{
    public function testTheFirstMultiplierOverTheThresholdIsFound(): void
    {
        // 0.3 at today's rps, threshold 0.8: 0.3 x 2.6 = 0.78, 0.3 x 2.7 = 0.81, so 2.7
        $r = LimitSearch::findProportional(0.3, 0.8);
        self::assertSame(LimitSearch::FOUND, $r['status']);
        self::assertSame(27, $r['k']);
        self::assertSame(2.7, $r['multiplier']);
    }

    public function testAUtilisationExactlyAtTheThresholdIsNotOver(): void
    {
        // 0.4 x 2.0 = 0.8 exactly at the threshold: not over; the next step, 2.1, is
        $r = LimitSearch::findProportional(0.4, 0.8);
        self::assertSame(21, $r['k']);
        self::assertSame(2.1, $r['multiplier']);
    }

    public function testFloatingPointNoiseDoesNotMakeTheThresholdLookOver(): void
    {
        // the product is a hair above 0.9 in floating point, but it is the threshold
        $calls = [];
        $r = LimitSearch::find(static function (int $k) use (&$calls): float {
            $calls[] = $k;

            return $k === 50 ? 0.9 + 1e-16 : 0.1;
        }, 0.9);
        self::assertSame(LimitSearch::NOT_WITHIN_CAP, $r['status']);
        self::assertContains(50, $calls);
    }

    public function testAThresholdReachedExactlyAtTenTimesIsNotReached(): void
    {
        $r = LimitSearch::findProportional(0.08, 0.8);
        self::assertSame(LimitSearch::NOT_WITHIN_CAP, $r['status']);
        self::assertNull($r['k']);
        self::assertNull($r['multiplier']);
    }

    public function testOverTheThresholdAtTenTimesIsTheCapItself(): void
    {
        $r = LimitSearch::findProportional(0.0801, 0.8); // x9.9 = 0.793 (under), x10 = 0.801 (over)
        self::assertSame(100, $r['k']);
        self::assertSame(10.0, $r['multiplier']);
        self::assertSame(LimitSearch::FOUND, $r['status']);
    }

    public function testALoadThatIsOverAlreadyIsSaidSo(): void
    {
        // 0.9 today: 0.9 x 0.8 = 0.72 is under, 0.9 x 0.9 = 0.81 is over -> 0.9x, which is below 1.0x
        $r = LimitSearch::findProportional(0.9, 0.8);
        self::assertSame(LimitSearch::ALREADY_OVER, $r['status']);
        self::assertSame(9, $r['k']);
        self::assertSame(0.9, $r['multiplier']);
    }

    public function testExactlyOneTimesOverIsAlreadyOver(): void
    {
        $r = LimitSearch::findProportional(0.85, 0.8); // 0.85 x 0.9 = 0.765 under, x 1.0 = 0.85 over
        self::assertSame(10, $r['k']);
        self::assertSame(LimitSearch::ALREADY_OVER, $r['status']);
    }

    public function testAGreatLoadIsOverAtTheFirstStep(): void
    {
        $r = LimitSearch::findProportional(100.0, 0.8); // x 0.1 = 10
        self::assertSame(1, $r['k']);
        self::assertSame(0.1, $r['multiplier']);
        self::assertSame(LimitSearch::ALREADY_OVER, $r['status']);
    }

    public function testNoLoadIsNeverOver(): void
    {
        self::assertSame(LimitSearch::NOT_WITHIN_CAP, LimitSearch::findProportional(0.0, 0.8)['status']);
    }

    public function testTheMultiplierIsAWholeStepNotASum(): void
    {
        // the search asks for step k and gives the utilisation at k / 10; it is asked for 1, 2, ..., never for a running sum of 0.1
        $asked = [];
        LimitSearch::find(static function (int $k) use (&$asked): float {
            $asked[] = $k;

            return 0.0;
        }, 0.8);
        self::assertSame(range(1, 100), $asked);
    }
}
