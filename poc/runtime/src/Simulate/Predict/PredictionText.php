<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/** The prediction as a person reads it in a terminal. Made from the report array alone; it adds no number of its own. */
final class PredictionText
{
    /** @param array<string, mixed> $report PredictionReport::build() */
    public static function render(array $report): string
    {
        $res = $report['resources'];
        $out = [];
        if ($report['low_coverage_scenarios'] !== []) {
            $out[] = 'WARNING low_coverage: more than 20% of the rps has no cost in ' . implode(', ', $report['low_coverage_scenarios']) . '. Read those predictions as a floor.';
            $out[] = '';
        }
        $cal = $report['calibration'];
        $out[] = sprintf(
            'peak-sim simulate%s: %s cores, threshold %s, calibrated by %s on %d scenario(s)',
            $report['app'] === null ? '' : ' of ' . $report['app'],
            self::num($res['cores']),
            self::pct($res['threshold']),
            $cal['method'],
            $cal['calibration_scenarios'],
        );
        if ($cal['cut_coefficients']) {
            $out[] = 'NOTE a calibration coefficient was cut to 0 or pinned (see the notes of the fits in the JSON)';
        }
        foreach ($cal['warnings'] as $w) {
            $out[] = 'NOTE ' . $w;
        }
        foreach ($cal['fits'] as $f) {
            $out[] = sprintf('  fit %-16s c_query %s  c_row %s  squared residual (load) %s', $f['kind'], self::num($f['c_query']), self::num($f['c_row']), self::num($f['sum_squared_residual_load']));
        }

        foreach ($report['scenarios'] as $s) {
            $out[] = '';
            $u = $s['utilization'];
            $out[] = sprintf('scenario %s%s', $s['id'], $s['low_coverage'] ? '   [LOW COVERAGE]' : '');
            foreach ($s['warnings'] as $w) {
                $out[] = '  WARNING ' . $w;
            }
            $out[] = sprintf(
                '  utilization  point %s   upper side %s   range %s to %s   (threshold %s: point %s, upper side %s)',
                self::pct($u['point']),
                self::pct($u['upper']),
                self::pct($u['range']['min']),
                self::pct($u['range']['max']),
                self::pct($u['threshold']),
                $u['over_threshold']['point'] ? 'over' : 'under',
                $u['over_threshold']['upper'] ? 'over' : 'under',
            );
            $out[] = sprintf('               range basis: estimate %s x calibration %s', implode('/', $u['range_basis']['estimate']), implode('/', $u['range_basis']['calibration_fits']));
            $l = $s['limit_multiplier'];
            $out[] = sprintf('  limit        point %s   safe side %s', self::limit($l['point']), self::limit($l['safe']));
            $out[] = sprintf(
                '  coverage     %d route(s): %d marked (%s of rps), %d unmarked, %d with no cost (%s of rps); extrapolated %d, interpolated %d, measured %d',
                $s['coverage']['routes'],
                $s['coverage']['marked']['routes'],
                self::pct($s['coverage']['marked']['rps_share']),
                $s['coverage']['unmarked']['routes'],
                count($s['coverage']['unknown_cost_routes']),
                self::pct($s['coverage']['unknown_cost_rps_share']),
                $s['n_marks']['extrapolated'],
                $s['n_marks']['interpolated'],
                $s['n_marks']['measured'],
            );
            $out[] = '  dominant routes (share of load):';
            foreach ($s['dominant_routes'] as $d) {
                $h = $d['heaviest_statement'];
                $out[] = sprintf(
                    '    %5s  %s%s%s',
                    self::pct($d['share']),
                    $d['route'],
                    $d['marked'] ? '  [marked]' : '',
                    $h === null ? '' : '  heaviest: ' . self::marks($h['marks']) . self::cut($h['pattern'], 70),
                );
            }
            foreach ($s['coverage']['unknown_cost_routes'] as $r) {
                $out[] = sprintf('  no cost: %s (rps %s): %s', $r['route'], self::num($r['rps']), $r['reason']);
            }
            if ($s['unknown_tables'] !== []) {
                $out[] = '  tables with no count: ' . implode(', ', $s['unknown_tables']);
            }
            if ($s['zero_rps_routes'] !== []) {
                $out[] = '  rps 0 (not in the scenario): ' . count($s['zero_rps_routes']) . ' route(s)';
            }
            if ($s['sensitivity']['top'] !== []) {
                $out[] = '  most influential inputs (+10% of one input, change of the limit multiplier):';
                foreach ($s['sensitivity']['top'] as $x) {
                    $out[] = sprintf(
                        '    %-40s %s -> %s  (%+.1fx; unrounded %+.3fx)',
                        self::cut($x['input'], 40),
                        self::limitValue($x['limit_before']),
                        self::limitValue($x['limit_after']),
                        $x['change'],
                        $x['change_unrounded'],
                    );
                }
            }
            if ($s['sensitivity']['not_evaluated'] !== []) {
                $out[] = '  not evaluated for sensitivity: ' . implode(', ', $s['sensitivity']['not_evaluated']);
            }
        }

        $out[] = '';
        $out[] = sprintf('PHP memory (line = %s of the limit; shown only for routes whose formula held within +-30%%):', self::pct($report['peak_memory']['line_share_of_limit']));
        $shown = 0;
        $notShown = [];
        foreach ($report['peak_memory']['routes'] as $m) {
            if ($m['status'] === PeakMemoryLimit::SHOWN) {
                $shown++;
                $out[] = sprintf('  %-60s %s', $m['route'], $m['n'] === null ? 'not reached' : 'N = ' . number_format($m['n']) . ($m['table'] === null ? '' : ' (' . $m['table'] . ')'));
            } else {
                $notShown[$m['reason']] = ($notShown[$m['reason']] ?? 0) + 1;
            }
        }
        foreach ($notShown as $reason => $n) {
            $out[] = sprintf('  %d route(s) not shown: %s', $n, $reason === PeakMemoryLimit::REASON_ACCURACY ? 'accuracy too low (insufficient_accuracy)' : $reason);
        }
        if ($shown === 0 && $notShown === []) {
            $out[] = '  no route has a peak-memory formula';
        }

        $a = $report['accuracy_on_this_app'];
        $out[] = '';
        if ($a['status'] === AccuracyRecord::CHECKED) {
            $out[] = sprintf('accuracy on this app: selfcheck %s at %s rows, %s: %s', (string) $a['checked_at'], number_format((int) $a['target_scale']), (string) $a['app'], strtoupper((string) $a['verdict']));
        } else {
            $out[] = 'accuracy on this app: not checked (' . $a['why'] . ')';
        }

        return implode("\n", $out) . "\n";
    }

    /** @param array{status: string, multiplier: float|null} $l */
    private static function limit(array $l): string
    {
        return match ($l['status']) {
            LimitSearch::NOT_WITHIN_CAP => 'not reached within 10x',
            LimitSearch::ALREADY_OVER => sprintf('%.1fx (already over at today\'s rps)', $l['multiplier']),
            default => sprintf('%.1fx', $l['multiplier']),
        };
    }

    private static function limitValue(?float $m): string
    {
        return $m === null ? '>10x' : sprintf('%.1fx', $m);
    }

    /** @param array{plan_changes_at_scale: bool, plan_unstable: bool} $marks */
    private static function marks(array $marks): string
    {
        $names = array_keys(array_filter($marks));

        return $names === [] ? '' : '[' . implode(',', $names) . '] ';
    }

    private static function pct(float $x): string
    {
        return sprintf('%.1f%%', 100.0 * $x);
    }

    private static function num(int|float $x): string
    {
        return sprintf('%.6g', $x);
    }

    private static function cut(string $s, int $max): string
    {
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return strlen($s) > $max ? substr($s, 0, $max - 3) . '...' : $s;
    }
}
