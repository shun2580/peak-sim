<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

/**
 * The seam between the acceptance tests and the tool. The real implementation calls the facade
 * (PeakSim\Application\AnalysisRunner); the harness self-tests use a stand-in so the test logic can be
 * proved without the tool.
 *
 * Every route comes back as a "view": the normalised array of ReportAssert::view().
 *
 * Options: max_depth (int), view_path (string), artisan_command (string; then there is no export dir).
 */
interface MiniAppAnalyzer
{
    /** @return array<string, array<string, mixed>> views keyed by route uri, in report order */
    public function analyzeAll(string $appRoot, ?string $exportDir, array $options = []): array;

    /** @return array<string, mixed> the view of one route */
    public function analyzeRoute(string $appRoot, ?string $exportDir, string $uri, array $options = []): array;

    /** The JSON of the whole report: the bytes the determinism and input-mode tests compare. */
    public function json(string $appRoot, ?string $exportDir, array $options = []): string;
}
