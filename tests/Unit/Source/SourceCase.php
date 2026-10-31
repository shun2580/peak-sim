<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Source;

use PeakSim\Source\AppSourceIndex;
use PHPUnit\Framework\TestCase;

/**
 * Shared helpers for the Source tests. Everything that depends on the exact shape of the
 * value objects returned by the contracts goes through {@see SourceShape}.
 */
abstract class SourceCase extends TestCase
{
    protected static function appPath(string $case): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Apps/' . $case;
    }

    protected static function index(string $case): AppSourceIndex
    {
        return new AppSourceIndex(self::appPath($case));
    }
}
