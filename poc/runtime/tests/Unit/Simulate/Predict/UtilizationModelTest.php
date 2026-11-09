<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Predict;

use PeakSim\Runtime\Simulate\Predict\Coefficients;
use PeakSim\Runtime\Simulate\Predict\UtilizationModel;
use PHPUnit\Framework\TestCase;

final class UtilizationModelTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function routes(): array
    {
        return [
            PredictFixtures::route('GET /a', 10.0, 5.0, 1000.0),
            PredictFixtures::route('GET /b', 5.0, 2.0, 400.0, 4000.0),
            PredictFixtures::unknown('GET /c', 7.0),
        ];
    }

    public function testTheLoadIsTheSumOfRpsTimesTheCoefficientsAndTheQuantities(): void
    {
        $c = new Coefficients('x', 0.001, 0.0001);
        // GET /a: 10 x (0.001 x 5 + 0.0001 x 1000) = 10 x 0.105 = 1.05;  GET /b: 5 x (0.001 x 2 + 0.0001 x 400) = 5 x 0.042 = 0.21
        self::assertEqualsWithDelta(1.26, UtilizationModel::load(self::routes(), $c), 1e-12);
        self::assertEqualsWithDelta(['GET /a' => 1.05, 'GET /b' => 0.21], UtilizationModel::perRoute(self::routes(), $c), 1e-12);
    }

    public function testTheUpperSideReplacesTheRowsOfAMarkedRouteOnly(): void
    {
        $c = new Coefficients('x', 0.001, 0.0001);
        // GET /b with 4000 rows: 5 x (0.002 + 0.4) = 2.01;  GET /a keeps 1.05
        self::assertEqualsWithDelta(3.06, UtilizationModel::load(self::routes(), $c, UtilizationModel::UPPER), 1e-12);
    }

    public function testARouteWithNoCostAddsNothingAndIsNotCountedAsZeroCost(): void
    {
        $rates = UtilizationModel::rates(self::routes(), UtilizationModel::POINT);
        // queries 10 x 5 + 5 x 2 = 60; rows 10 x 1000 + 5 x 400 = 12000; the 7 rps of GET /c are in neither
        self::assertSame(60.0, $rates['queries']);
        self::assertSame(12000.0, $rates['rows']);
    }

    public function testLowAndHighTakeTheGivenBoundsAndElseThePointAndTheUpperSide(): void
    {
        $r = PredictFixtures::route('GET /d', 1.0, 10.0, 100.0, 300.0, ['queries_low' => 8.0, 'queries_high' => 12.0, 'rows_low' => 50.0]);
        self::assertSame([8.0, 50.0], UtilizationModel::quantities($r, UtilizationModel::LOW));
        self::assertSame([12.0, 300.0], UtilizationModel::quantities($r, UtilizationModel::HIGH)); // no rows_high: the larger of point and upper
        $plain = PredictFixtures::route('GET /e', 1.0, 10.0, 100.0);
        self::assertSame([10.0, 100.0], UtilizationModel::quantities($plain, UtilizationModel::LOW));
        self::assertSame([10.0, 100.0], UtilizationModel::quantities($plain, UtilizationModel::HIGH));
    }

    public function testACoefficientBelowZeroIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Coefficients('x', -0.1, 0.0);
    }

    public function testThePointCoefficientsOfAOneCoefficientCalibrationAreTheMeanOfTheFits(): void
    {
        $c = Coefficients::pointOf(PredictFixtures::oneCoefficient(0.002, 0.0004));
        self::assertSame(0.001, $c->cQ);
        self::assertSame(0.0002, $c->cRow);
        $two = Coefficients::pointOf(PredictFixtures::twoCoefficient(0.003, 0.0005, 0.01, 0.01));
        self::assertSame(0.003, $two->cQ);
        self::assertSame(0.0005, $two->cRow);
    }
}
