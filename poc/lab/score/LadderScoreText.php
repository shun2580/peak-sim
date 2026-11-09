<?php

declare(strict_types=1);

namespace PeakSim\Lab\Score;

/**
 * Writes the result of LadderScore::score() as Markdown: the verdict and the four items with their lines, a table per scenario
 * (each stage: prediction point / safe / naive, observed, differences, the threshold call; the measured limit rps and the three
 * predicted ones), and every stage whose safe-side prediction fell below the observation. Pure: no file, no clock.
 */
final class LadderScoreText
{
    /**
     * @param array<string, mixed> $score the result of LadderScore::score()
     */
    public static function render(array $score): string
    {
        $out = [];
        $out[] = '# Lab backtest: score of the ladders';
        $out[] = '';
        $out[] = 'Threshold: ' . self::pct((float) $score['threshold']) . '. Usable stage: valid and observed ' . self::pct($score['usable_range'][0])
            . ' to ' . self::pct($score['usable_range'][1]) . '.';
        $c = $score['counts'];
        $out[] = 'Scenarios ' . $c['scenarios'] . ' · stages measured ' . $c['stages_measured'] . ' · valid ' . $c['valid_stages']
            . ' · usable ' . $c['usable_stages'] . ' · scenarios whose limit rps was measured ' . $c['scenarios_with_limit'] . '.';
        $out[] = '';
        $out[] = '## Verdict: ' . self::verdictText((string) $score['verdict']);
        if ($score['indeterminate']['is']) {
            $out[] = '';
            foreach ($score['indeterminate']['reasons'] as $reason) {
                $out[] = '- Indeterminate: ' . $reason;
            }
            $out[] = '- An indeterminate backtest is not a pass. The items below are shown for the record only.';
        }
        $out[] = '';

        $i = $score['items'];
        $out[] = '| Item | Value | Minimum | Target |';
        $out[] = '| --- | --- | --- | --- |';
        $out[] = '| 1. mean abs. difference smaller than the naive estimate | ' . self::points($i['1']['mean_points']) . ' vs naive '
            . self::points($i['1']['mean_points_naive']) . ' | ' . self::mark($i['1']['minimum']) . ' | ' . self::mark($i['1']['target']) . ' |';
        $out[] = '| 2. mean abs. difference (lines ' . self::num($i['2']['line_minimum_points']) . ' / ' . self::num($i['2']['line_target_points'])
            . ' points) | ' . self::points($i['2']['mean_points']) . ' | ' . self::mark($i['2']['minimum']) . ' | ' . self::mark($i['2']['target']) . ' |';
        $out[] = '| 3. "exceeded the threshold" agrees, all valid stages (80% or more) | ' . $i['3']['agree'] . '/' . $i['3']['valid_stages']
            . ' = ' . self::share($i['3']['share']) . ' (naive ' . self::share($i['3']['share_naive']) . ') | ' . self::mark($i['3']['minimum'])
            . ' | ' . self::mark($i['3']['target']) . ' |';
        $out[] = '| 4. predicted limit rps within ±' . self::pct($i['4']['tolerance_minimum']) . ' / ±' . self::pct($i['4']['tolerance_target'])
            . ' of the measured one (80% or more) | ' . $i['4']['within_minimum'] . '/' . $i['4']['scenarios_with_limit'] . ' and '
            . $i['4']['within_target'] . '/' . $i['4']['scenarios_with_limit'] . ' | ' . self::mark($i['4']['minimum']) . ' | ' . self::mark($i['4']['target']) . ' |';
        $out[] = '';
        $out[] = 'Item 4 for the other two predictions, for the record (not used for the verdict): safe side '
            . $i['4']['safe_within_minimum'] . '/' . $i['4']['scenarios_with_limit'] . ' and ' . $i['4']['safe_within_target'] . '/'
            . $i['4']['scenarios_with_limit'] . '; naive estimate ' . $i['4']['naive_within_minimum'] . '/' . $i['4']['scenarios_with_limit']
            . ' and ' . $i['4']['naive_within_target'] . '/' . $i['4']['scenarios_with_limit'] . ' (within the minimum and the target tolerance).';
        $out[] = '';

        foreach ($score['scenarios'] as $scenario) {
            $out[] = '## Scenario ' . $scenario['id'];
            $out[] = '';
            $out[] = '| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |';
            $out[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |';
            foreach ($scenario['stages'] as $s) {
                if ($s['observed'] === null) {
                    $out[] = '| ' . self::num($s['rps']) . ' | ' . self::pct($s['predicted']['point']) . ' | ' . self::pct($s['predicted']['safe']) . ' | '
                        . self::pct($s['predicted']['naive']) . ' | not measured | | | | | | | |';
                    continue;
                }
                $out[] = '| ' . self::num($s['rps']) . ' | ' . self::pct($s['predicted']['point']) . ' | ' . self::pct($s['predicted']['safe']) . ' | '
                    . self::pct($s['predicted']['naive']) . ' | ' . self::pct($s['observed']) . ' | ' . ($s['valid'] ? 'yes' : '**no**') . ' | '
                    . ($s['usable'] ? 'yes' : 'no') . ' | ' . self::signed($s['difference_points']['point']) . ' | ' . self::signed($s['difference_points']['naive'])
                    . ' | ' . ($s['over_threshold']['point'] ? 'yes' : 'no') . ' | ' . ($s['over_threshold']['observed'] ? 'yes' : 'no') . ' | '
                    . ($s['agree']['point'] ? 'yes' : 'no') . ' |';
            }
            $out[] = '';
            $m = $scenario['limit']['measured'];
            $p = $scenario['limit']['predicted'];
            if ($m['status'] === 'measured') {
                $how = $m['method'] === 'interpolated' ? 'interpolated between two valid stages' : '**extended** from two valid stages (not interpolated)';
                $out[] = 'Measured limit rps: ' . self::num($m['rps']) . ' (' . $how . ').';
            } else {
                $out[] = 'Measured limit rps: not measured (' . $m['reason'] . ').';
            }
            $out[] = 'Predicted limit rps: point ' . self::rps($p['point']) . ', safe ' . self::rps($p['safe']) . ', naive ' . self::rps($p['naive'])
                . ($m['status'] === 'measured'
                    ? '. Relative error: point ' . self::relative($scenario['limit']['relative_error']['point']) . ', safe '
                        . self::relative($scenario['limit']['relative_error']['safe']) . ', naive ' . self::relative($scenario['limit']['relative_error']['naive']) . '.'
                    : '.');
            $out[] = '';
        }

        $out[] = '## Stages where the safe-side prediction fell below the observation';
        $out[] = '';
        if ($score['safe_side_below_observed'] === []) {
            $out[] = 'None.';
        } else {
            $out[] = '| scenario | rps | safe | observed | valid | usable |';
            $out[] = '| --- | --- | --- | --- | --- | --- |';
            foreach ($score['safe_side_below_observed'] as $v) {
                $out[] = '| ' . $v['scenario'] . ' | ' . self::num($v['rps']) . ' | ' . self::pct($v['safe']) . ' | ' . self::pct($v['observed']) . ' | '
                    . ($v['valid'] ? 'yes' : 'no') . ' | ' . ($v['usable'] ? 'yes' : 'no') . ' |';
            }
        }
        $out[] = '';

        return implode("\n", $out);
    }

    private static function verdictText(string $verdict): string
    {
        return match ($verdict) {
            'pass_target' => 'PASS at the target',
            'pass_minimum' => 'PASS at the minimum, target not reached',
            'fail' => 'FAIL (the minimum is not met)',
            'indeterminate' => 'INDETERMINATE',
            default => $verdict,
        };
    }

    private static function mark(bool $met): string
    {
        return $met ? 'met' : 'not met';
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }

    private static function pct(float $fraction): string
    {
        return number_format($fraction * 100.0, 1, '.', '') . '%';
    }

    private static function points(?float $v): string
    {
        return $v === null ? 'n/a' : number_format($v, 2, '.', '') . ' pt';
    }

    private static function signed(float $v): string
    {
        return ($v >= 0 ? '+' : '') . number_format($v, 2, '.', '');
    }

    private static function share(?float $v): string
    {
        return $v === null ? 'n/a' : number_format($v * 100.0, 1, '.', '') . '%';
    }

    private static function rps(?float $v): string
    {
        return $v === null ? 'none' : self::num($v);
    }

    private static function relative(?float $v): string
    {
        return $v === null ? 'n/a' : ($v >= 0 ? '+' : '') . number_format($v * 100.0, 1, '.', '') . '%';
    }
}
