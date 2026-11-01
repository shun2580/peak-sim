<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Run\ActingAs;
use PHPUnit\Framework\TestCase;

final class ActingAsTest extends TestCase
{
    public function testParsesClassKeyAndGuard(): void
    {
        self::assertSame(['App\\Models\\User', '1', 'web'], ActingAs::parse('App\\Models\\User:1:web'));
    }

    public function testGuardIsOptional(): void
    {
        self::assertSame(['App\\Models\\User', 'abc-123', null], ActingAs::parse('App\\Models\\User:abc-123'));
    }

    public function testRefusesNonsense(): void
    {
        foreach (['', 'User', ':1', 'App\\User:', 'a b:1', 'App\\User:1:we b'] as $bad) {
            try {
                ActingAs::parse($bad);
                self::fail("accepted: $bad");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
