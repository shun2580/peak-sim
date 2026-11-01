<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Route\RouteSpec;
use PHPUnit\Framework\TestCase;

abstract class PlannerTestCase extends TestCase
{
    /** @param array<string, mixed> $static */
    protected static function spec(string $uri = '/ng/n-plus-one', array $static = [], string $method = 'GET', array $middleware = []): RouteSpec
    {
        return new RouteSpec($uri, $method, [$method], $middleware, [], 'A@b', $static + [
            'query' => ['growth' => 'O(N)', 'lower_bound' => null, 'confidence' => 'high'],
            'memory' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'n_table' => 'orders', 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => [],
        ], true);
    }
}
