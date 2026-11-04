<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Run\Requester;
use PHPUnit\Framework\TestCase;

final class RequesterTimeLimitTest extends TestCase
{
    public function testDefaultIs20Seconds(): void
    {
        self::assertSame(20, Requester::timeLimit(''));
    }

    public function testAShorterLimitCanBeSetForATest(): void
    {
        self::assertSame(2, Requester::timeLimit('2'));
        self::assertSame(60, Requester::timeLimit('60'));
    }

    public function testALongRouteCanAskForUpToTenMinutes(): void
    {
        self::assertSame(100, Requester::timeLimit('100'));
        self::assertSame(600, Requester::timeLimit('600'));
    }

    public function testNonsenseFallsBackToTheDefault(): void
    {
        foreach (['0', '601', '1000', '-1', 'abc', '1.5', ''] as $value) {
            self::assertSame(20, Requester::timeLimit($value), $value);
        }
    }
}
