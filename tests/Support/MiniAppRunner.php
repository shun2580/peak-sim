<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

/** Runs one mini-app case through an analyzer and lists everything that disagrees with its expect.php. */
final class MiniAppRunner
{
    public function __construct(private readonly MiniAppAnalyzer $analyzer)
    {
    }

    /** @return list<string> problems; empty when the case passes */
    public function verify(MiniAppCase $case): array
    {
        $problems = $case->problems();
        if ($problems !== []) {
            return $problems;
        }
        if (!is_dir($case->dir . '/app')) {
            return ["{$case->name}: app/ is missing — the app has not been written yet"];
        }
        $expect = $case->expect();
        $exportDir = MiniAppWorkspace::make('export');
        try {
            MiniAppExport::writeTo($case->export(), $exportDir);
            $views = $this->analyzer->analyzeAll($case->dir, $exportDir, $expect['config'] ?? []);
        } finally {
            MiniAppWorkspace::remove($exportDir);
        }
        $problems = array_map(static fn (string $p): string => "{$case->name}: {$p}", Expectation::check($expect, $views));
        foreach ($views as $view) {
            foreach (ReportAssert::invariants($view) as $violation) {
                $problems[] = "{$case->name}: {$violation}";
            }
        }

        return $problems;
    }
}
