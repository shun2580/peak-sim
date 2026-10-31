<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

use PeakSim\Application\AnalysisConfig;
use PeakSim\Application\AnalysisRunner;
use PeakSim\Model\AnalysisReport;

/** The real tool, through the facade of design 2.3. Until PW lands, every call fails because the class is missing. */
final class MiniAppRealAnalyzer implements MiniAppAnalyzer
{
    public function analyzeAll(string $appRoot, ?string $exportDir, array $options = []): array
    {
        return ReportAssert::views($this->report($appRoot, $exportDir, $options));
    }

    public function analyzeRoute(string $appRoot, ?string $exportDir, string $uri, array $options = []): array
    {
        return ReportAssert::view($this->runner($appRoot, $exportDir, $options)->analyzeRoute($uri));
    }

    public function json(string $appRoot, ?string $exportDir, array $options = []): string
    {
        return ReportAssert::json($this->report($appRoot, $exportDir, $options));
    }

    private function report(string $appRoot, ?string $exportDir, array $options): AnalysisReport
    {
        return $this->runner($appRoot, $exportDir, $options)->analyzeAll();
    }

    private function runner(string $appRoot, ?string $exportDir, array $options): AnalysisRunner
    {
        return AnalysisRunner::create(new AnalysisConfig(
            appRoot: $appRoot,
            artisanCommand: $options['artisan_command'] ?? null,
            exportDir: $exportDir,
            maxDepth: $options['max_depth'] ?? 20,
            viewPath: $options['view_path'] ?? null,
        ));
    }
}
