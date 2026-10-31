<?php

declare(strict_types=1);

namespace PeakSim\Output;

use PeakSim\Model\AnalysisReport;
use PeakSim\Model\Growth;
use PeakSim\Model\RouteResult;

/**
 * The counts at the end of a table and in the "summary" of the JSON, computed once for both.
 *
 * Only routes whose kind is "controller" are counted as analysed. Everything else (the report's skipped
 * entries and any route of another kind) is "skipped".
 */
final readonly class ReportSummary
{
    /**
     * @param array{high: int, medium: int, none: int} $byConfidence
     * @param array{query: array<string, int>, memory: array<string, int>} $byGrowth
     * @param array<string, int> $gapReasons gap code => number of routes that have it, sorted by code
     */
    private function __construct(
        public int $routes,
        public array $byConfidence,
        public array $byGrowth,
        public array $gapReasons,
        public int $skipped,
    ) {
    }

    public static function of(AnalysisReport $report): self
    {
        $analysed = self::analysed($report);
        $confidence = ['high' => 0, 'medium' => 0, 'none' => 0];
        $growth = ['query' => [], 'memory' => []];
        foreach (['query', 'memory'] as $axis) {
            foreach (Growth::cases() as $label) {
                $growth[$axis][$label->value] = 0;
            }
        }
        $reasons = [];
        foreach ($analysed as $route) {
            ++$confidence[$route->confidence?->value ?? 'none'];
            ++$growth['query'][$route->query->growth->value];
            ++$growth['memory'][$route->memory->growth->value];
            foreach (array_unique(array_map(static fn ($g): string => $g->code->value, $route->gaps)) as $code) {
                $reasons[$code] = ($reasons[$code] ?? 0) + 1;
            }
        }
        ksort($reasons, SORT_STRING);

        return new self(
            count($analysed),
            $confidence,
            $growth,
            $reasons,
            count($report->skipped) + (count($report->routes) - count($analysed)),
        );
    }

    /** @return list<RouteResult> the controller routes, in canonical order */
    public static function analysed(AnalysisReport $report): array
    {
        return array_values(array_filter(
            Canonical::routes($report->routes),
            static fn (RouteResult $r): bool => $r->kind === RouteResult::KIND_CONTROLLER,
        ));
    }
}
