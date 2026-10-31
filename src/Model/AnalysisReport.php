<?php

declare(strict_types=1);

namespace PeakSim\Model;

final readonly class AnalysisReport
{
    /**
     * @param list<RouteResult> $routes sorted by uri
     * @param list<array<string, mixed>> $skipped routes left out of the analysis (framework / closure)
     * @param array<string, mixed> $scope what the tool does not cover
     */
    public function __construct(
        public array $routes,
        public array $skipped,
        public array $scope,
    ) {
    }
}
