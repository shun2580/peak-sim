<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Model;

use PeakSim\Model\AxisVerdict;
use PeakSim\Model\Growth;
use PeakSim\Model\RouteResult;
use PHPUnit\Framework\TestCase;

/** nTableAlternatives is a trailing argument: existing constructor calls keep working. */
final class RouteResultTest extends TestCase
{
    private static function axis(): AxisVerdict
    {
        return new AxisVerdict(Growth::Constant, 0, null, null, null);
    }

    public function testNTableAlternativesDefaultsToEmpty(): void
    {
        $r = new RouteResult(
            '/a', ['GET'], 'A@b', RouteResult::KIND_CONTROLLER, self::axis(), self::axis(),
            null, null, null, null, null, null, [], [], [], [], 0, false,
        );

        self::assertSame([], $r->nTableAlternatives);
    }

    public function testNTableAlternativesKeepsTheTablesInTheGivenOrder(): void
    {
        $r = new RouteResult(
            '/a', ['GET'], 'A@b', RouteResult::KIND_CONTROLLER, self::axis(), self::axis(),
            'orders', null, null, null, null, null, [], [], [], [], 0, false,
            ['customers', 'items'],
        );

        self::assertSame('orders', $r->nTable);
        self::assertSame(['customers', 'items'], $r->nTableAlternatives);
    }
}
