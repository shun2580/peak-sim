<?php

declare(strict_types=1);

namespace PeakSim\Prototype;

/**
 * The verdict for one route. query / memory use the labels of fixtures/expected.json
 * ('O(1)' | 'O(N)' | 'O(N*M)' | 'unknown'); confidence is 'high' | 'medium' | 'low'.
 */
final class RouteAnalysis
{
    /** @param list<array{file: string, line: int, note: string}> $evidence file is relative to the app root */
    public function __construct(
        public readonly string $uri,
        public readonly string $query,
        public readonly string $memory,
        public readonly ?string $nTable,
        public readonly string $confidence,
        public readonly array $evidence,
    ) {
    }
}
