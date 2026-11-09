<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Backtest;

/** The backtest report as a table for a person (the JSON is for machines). Nothing here decides anything. */
final class BacktestText
{
    /** @param array<string, mixed> $report BacktestReport::build() */
    public static function render(array $report): string
    {
        $out = "\nbacktest: the prediction was written before the observed utilisation was read\n";
        $rows = (array) $report['scenarios'];
        if ($rows === []) {
            return $out . "  no target scenario has an observed value: nothing to compare\n";
        }
        $out .= sprintf("  %-14s %9s %9s %9s %9s %9s %9s\n", 'scenario', 'observed', 'predicted', 'diff', 'naive', 'naive diff', 'upper');
        foreach ($rows as $r) {
            $out .= sprintf(
                "  %-14s %9s %9s %9s %9s %9s %9s%s\n",
                $r['id'],
                self::pct((float) $r['observed']),
                self::pct((float) $r['predicted']['point']),
                self::points((float) $r['predicted']['difference']),
                self::pct((float) $r['naive']['utilization']),
                self::points((float) $r['naive']['difference']),
                self::pct((float) $r['predicted']['upper']),
                $r['low_coverage'] ? '  [low_coverage]' : '',
            );
        }
        foreach ((array) $report['target_scenarios_without_observed'] as $id) {
            $out .= sprintf("  %-14s no observed value: not compared\n", $id);
        }
        $s = (array) $report['summary'];
        $items = (array) $s['items'];
        $out .= sprintf(
            "\n  mean absolute difference: %s (naive %s) over %d scenario(s)\n",
            self::pct((float) $s['mean_absolute_difference']),
            self::pct((float) $s['mean_absolute_difference_naive']),
            (int) $s['scenarios_compared'],
        );
        $out .= sprintf("  1. smaller than the naive estimate: %s\n", $items['beats_naive']['value'] ? 'yes' : 'no');
        $d = $items['mean_difference_points'];
        $out .= sprintf(
            "  2. mean absolute difference %.1f points: minimum line %d %s, target line %d %s\n",
            $d['value'],
            (int) $d['minimum_line'],
            $d['meets_minimum'] ? 'met' : 'not met',
            (int) $d['target_line'],
            $d['meets_target'] ? 'met' : 'not met',
        );
        $t = $items['threshold_agreement'];
        $out .= $t['applies']
            ? sprintf("  3. \"over the threshold\" agrees in %.0f%% of the scenarios (naive %.0f%%): line %.0f%% %s\n", 100 * $t['share'], 100 * $t['share_naive'], 100 * $t['line'], $t['meets_line'] ? 'met' : 'not met')
            : sprintf("  3. not used: %s (agreement %.0f%%, naive %.0f%%)\n", $t['reason'], 100 * $t['share'], 100 * $t['share_naive']);

        return $out;
    }

    private static function pct(float $utilisation): string
    {
        return sprintf('%.1f%%', 100.0 * $utilisation);
    }

    private static function points(float $difference): string
    {
        return sprintf('%+.1f pt', 100.0 * $difference);
    }
}
