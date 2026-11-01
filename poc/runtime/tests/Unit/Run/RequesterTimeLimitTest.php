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

    public function testNonsenseFallsBackToTheDefault(): void
    {
        foreach (['0', '61', '-1', 'abc', '1.5', '100'] as $value) {
            self::assertSame(20, Requester::timeLimit($value), $value);
        }
    }
}
