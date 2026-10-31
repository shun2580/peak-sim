<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Output;

use PeakSim\Model\AnalysisReport;
use PeakSim\Output\ReportSummary;
use PHPUnit\Framework\TestCase;

final class ReportSummaryTest extends TestCase
{
    public function testItCountsOnlyControllerRoutesAndPutsTheRestUnderSkipped(): void
    {
        $summary = ReportSummary::of(ReportBuilder::report(ReportBuilder::inputSpec()));

        $this->assertSame(11, $summary->routes);
        $this->assertSame(3, $summary->skipped, 'two skipped closures and one framework route');
    }

    public function testConfidenceIsCountedHighMediumAndNone(): void
    {
        $summary = ReportSummary::of(ReportBuilder::report(ReportBuilder::inputSpec()));

        $this->assertSame(['high' => 7, 'medium' => 1, 'none' => 3], $summary->byConfidence);
    }

    public function testGrowthIsCountedPerAxisWithAllFourLabelsAlwaysPresent(): void
    {
        $summary = ReportSummary::of(ReportBuilder::report(ReportBuilder::inputSpec()));

        $this->assertSame(['O(1)' => 2, 'O(N)' => 5, 'O(N*M)' => 2, 'unknown' => 2], $summary->byGrowth['query']);
        $this->assertSame(['O(1)' => 1, 'O(N)' => 5, 'O(N*M)' => 2, 'unknown' => 3], $summary->byGrowth['memory']);
        $empty = ReportSummary::of(new AnalysisReport([], [], []));
        $this->assertSame(['O(1)' => 0, 'O(N)' => 0, 'O(N*M)' => 0, 'unknown' => 0], $empty->byGrowth['query']);
    }

    public function testAGapReasonIsCountedOncePerRouteNotOncePerGap(): void
    {
        $spec = ReportBuilder::inputSpec();
        foreach ($spec['routes'] as $i => $route) {
            if ($route['uri'] === '/unknown/dynamic') {
                $spec['routes'][$i]['gaps'][] = $route['gaps'][0];
            }
        }

        $summary = ReportSummary::of(ReportBuilder::report($spec));

        $this->assertSame(['DYNAMIC_METHOD_NAME' => 1, 'UNRESOLVED_CALL' => 2, 'VENDOR_CALL_NOT_MODELLED' => 1], $summary->gapReasons);
    }

    public function testGapReasonsAreSortedByCode(): void
    {
        $summary = ReportSummary::of(ReportBuilder::report(ReportBuilder::inputSpec()));

        $keys = array_keys($summary->gapReasons);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }
}
