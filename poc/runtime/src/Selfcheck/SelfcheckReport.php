<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Selfcheck;

/** The text a person reads at the end of a self-check. Made from the saved result only. */
final class SelfcheckReport
{
    /** @param array<string, mixed> $result the saved selfcheck-result */
    public static function table(array $result): string
    {
        $s = $result['summary'];
        $lines = [];
        $lines[] = sprintf('selfcheck of %s at %s rows: %s', (string) ($result['app'] ?? '?'), number_format((int) $result['target_scale']), strtoupper((string) $s['verdict']));
        foreach ((array) $s['reasons'] as $why) {
            $lines[] = '  ' . $why;
        }
        $lines[] = '';
        $lines[] = sprintf('%-15s %12s %22s %22s %10s', 'quantity', 'routes', 'inside lowest standard', 'inside goal', 'unmeasured');
        foreach (SelfcheckJudge::QUANTITIES as $q) {
            $x = $s['quantities'][$q];
            $lines[] = sprintf(
                '%-15s %12d %22s %22s %10d',
                $q,
                $x['denominator'],
                self::cell($x['within_lowest_standard'], $x['denominator'], $x['within_lowest_standard_percent'], $x['tolerance_percent']),
                self::cell($x['within_goal'], $x['denominator'], $x['within_goal_percent'], $x['goal_percent']),
                $x['unmeasured'],
            );
        }
        $lines[] = '';
        $lines[] = sprintf('routes: %d (unmarked %d, marked %d)', $s['routes'], $s['unmarked_routes'], $s['marked_routes']);
        $lines[] = 'plan_unstable statements: ' . ($s['plan_unstable_statements'] ?? 'unknown') . ', EXPLAIN unavailable statements: ' . ($s['explain_unavailable_statements'] ?? 'unknown');
        $lines[] = 'the verdict is by rule ' . $result['decides_by'] . ' (a marked route against the largest of its cold values)';
        foreach ((array) $result['problems'] as $p) {
            $lines[] = 'problem: ' . $p;
        }

        return implode("\n", $lines) . "\n";
    }

    private static function cell(int $n, int $d, ?float $percent, int $tolerance): string
    {
        return $percent === null ? 'n/a' : sprintf('%d/%d = %.1f%% (±%d%%)', $n, $d, $percent, $tolerance);
    }
}
