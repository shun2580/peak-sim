#!/usr/bin/env python3
"""Verdict script for B (the lab backtest): items 1 to 4, "undecidable", and the numbers condition 1b is counted with.

  python3 -I judge_b.py --prediction <prediction .json> --ladder-dir <dir> --out <dir> [--captured-max N] [--seen <file>]

  --ladder-dir   a directory whose sub-directories each hold one `ladder.json` (the record one scenario's measurement writes)
  --captured-max the largest row count the estimate was captured at (the multiple of it is written; 5 or more is needed
                 for condition 1b, spec 7.4.4). Without it the multiple is "not given" and 1b is never counted
  --seen         a text file, one row count per line, of the counts already seen (comments after `#`). Without it
                 "not seen before" cannot be shown and 1b is never counted

What it does: it runs the committed tool `poc/lab/score/score-ladder` (the rules of spec 7.2 and 7.4 B are in that tool and are not
copied or changed here), reads the `score.json` it writes, and puts the figures in the order the spec and D153/D167 name them.
It reads files only: no database, no app, no clock. The same inputs always give the same output.
It writes `b-verdict.json` and `b-verdict.md` and `score/` (the tool's own `score.json` and `score.md`) under --out.
Exit 0 whatever the verdict (a fail or an undecidable is in the output); 2 wrong usage or an input the tool refuses; 3 the tool did not run.
"""
import argparse, glob, json, math, os, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))


def repo_root():
    out = subprocess.run(['git', '-C', HERE, 'rev-parse', '--show-toplevel'], capture_output=True, text=True)
    if out.returncode != 0:
        sys.exit('judge_b: not inside a git work tree (the score tool is found from the repository root)')
    return out.stdout.strip()


def ceil_share(n, num=8, den=10):
    """ceil(0.8 * n) in whole numbers."""
    return -((-num * n) // den)


def read_seen(path):
    seen = set()
    with open(path) as fh:
        for line in fh:
            line = line.split('#', 1)[0].strip().replace(',', '')
            if line:
                seen.add(int(line))
    return seen


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--prediction', required=True)
    ap.add_argument('--ladder-dir', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--captured-max', type=int)
    ap.add_argument('--seen')
    a = ap.parse_args()

    ladders = sorted(glob.glob(os.path.join(a.ladder_dir, '*', 'ladder.json')))
    if not ladders:
        print('judge_b: no <dir>/*/ladder.json under ' + a.ladder_dir, file=sys.stderr)
        return 2
    os.makedirs(a.out, exist_ok=True)
    score_dir = os.path.join(a.out, 'score')
    cmd = ['bash', os.path.join(repo_root(), 'poc/lab/score/score-ladder'), '--prediction=' + a.prediction]
    cmd += ['--ladder=' + p for p in ladders] + ['--out=' + score_dir]
    run = subprocess.run(cmd, capture_output=True, text=True)
    if run.returncode == 2:
        print('judge_b: the score tool refused the input:\n' + run.stderr, file=sys.stderr)
        return 2
    if run.returncode != 0:
        print('judge_b: the score tool did not run (exit %d):\n%s' % (run.returncode, run.stderr), file=sys.stderr)
        return 3
    score = json.load(open(os.path.join(score_dir, 'score.json')))
    pred = json.load(open(a.prediction))
    scale = pred.get('estimate_at')

    c, it = score['counts'], score['items']
    usable, with_limit = c['usable_stages'], c['scenarios_with_limit']
    undecidable = bool(score['indeterminate']['is'])
    reasons = []
    if usable < 10:
        reasons.append('usable steps %d, fewer than 10' % usable)
    if with_limit < 4:
        reasons.append('scenarios whose limit rps was measured %d, fewer than 4' % with_limit)
    # the tool and this script must say the same thing about "undecidable"
    consistent = undecidable == bool(reasons)

    # the numbers of condition 1b as D153 and D167 count them (target; the lowest standard beside it)
    i1, i2, i3, i4 = it['1'], it['2'], it['3'], it['4']
    short3 = max(0, ceil_share(i3['valid_stages']) - i3['agree'])
    short4_goal = max(0, ceil_share(i4['scenarios_with_limit']) - i4['within_target'])
    short4_min = max(0, ceil_share(i4['scenarios_with_limit']) - i4['within_minimum'])
    one_b = {
        'item1_smaller_than_naive': bool(i1['minimum']),
        'item2_excess_points_over_5': max(0.0, i2['mean_points'] - i2['line_target_points']),
        'item3_agree': i3['agree'], 'item3_valid_stages': i3['valid_stages'], 'item3_short': short3,
        'item4_within_goal': i4['within_target'], 'item4_within_minimum': i4['within_minimum'],
        'item4_scenarios_with_limit': i4['scenarios_with_limit'], 'item4_short_goal': short4_goal, 'item4_short_minimum': short4_min,
    }

    # spec 7.4.4: 1b is counted only for a B that passes at the goal, at 5 times the capture's largest size or more, at a count not seen
    goal_met = (not undecidable) and all(it[k]['target'] for k in ('1', '2', '3', '4'))
    min_met = (not undecidable) and all(it[k]['minimum'] for k in ('1', '2', '3', '4'))
    verdict = 'undecidable' if undecidable else ('pass' if min_met else 'fail')
    multiple = None if (a.captured_max is None or scale is None) else scale / a.captured_max
    seen_state, seen_note = 'not given', 'no --seen file: "not seen before" is not shown'
    if a.seen:
        seen = read_seen(a.seen)
        seen_state = 'seen' if scale in seen else 'not seen'
        seen_note = '%d counts in %s' % (len(seen), os.path.basename(a.seen))
    why_not = []
    if not goal_met:
        why_not.append('B is %s' % verdict if undecidable or not min_met else 'B passes the lowest standard but not the goal')
    if multiple is None:
        why_not.append('the multiple of the capture is not given')
    elif multiple < 5:
        why_not.append('the multiple of the capture is %.3f, below 5' % multiple)
    if seen_state != 'not seen':
        why_not.append('the count is ' + ('seen before' if seen_state == 'seen' else 'not shown to be unseen'))
    counted = not why_not

    out = {
        'prediction_file': a.prediction, 'rows': scale, 'captured_max': a.captured_max, 'multiple_of_capture': multiple,
        'ladders': [os.path.relpath(p, a.ladder_dir) for p in ladders],
        'counts': c, 'verdict': verdict, 'undecidable': undecidable, 'undecidable_reasons': reasons,
        'tool_and_script_agree_on_undecidable': consistent,
        'items': it, 'goal_met': goal_met, 'minimum_met': min_met,
        'condition_1b': one_b, 'condition_1b_counted': counted, 'condition_1b_not_counted_because': why_not,
        'seen_state': seen_state,
    }
    json.dump(out, open(os.path.join(a.out, 'b-verdict.json'), 'w'), indent=1, sort_keys=True)
    open(os.path.join(a.out, 'b-verdict.json'), 'a').write('\n')

    def yes(x):
        return 'met' if x else 'not met'
    L = []
    L.append('# B verdict (judge_b.py)\n')
    L.append('Prediction: `%s`; rows %s; captured max %s; multiple of the capture %s; seen-before check: %s (%s).\n' % (
        a.prediction, scale, a.captured_max if a.captured_max is not None else 'not given',
        ('%.3f' % multiple) if multiple is not None else 'not given', seen_state, seen_note))
    L.append('Scenarios %d, steps measured %d, valid %d, usable (valid and 10%% to 90%%) %d, scenarios whose limit rps was measured %d.\n' % (
        c['scenarios'], c['stages_measured'], c['valid_stages'], usable, with_limit))
    L.append('**Verdict: %s.**%s\n' % (verdict.upper(), (' Undecidable because: ' + '; '.join(reasons) + '.') if undecidable else ''))
    if not consistent:
        L.append('**The tool and this script disagree on "undecidable". Stop and read both.**\n')
    L.append('| Item | Value | Lowest standard | Goal |\n| --- | --- | --- | --- |')
    L.append('| 1. mean abs. difference smaller than the naive estimate | point %.3f pt; naive %.3f pt | %s | %s |' % (
        i1['mean_points'], i1['mean_points_naive'], yes(i1['minimum']), yes(i1['target'])))
    L.append('| 2. mean abs. difference (15 / 5 points) | %.3f pt over %d usable steps | %s | %s |' % (
        i2['mean_points'], usable, yes(i2['minimum']), yes(i2['target'])))
    L.append('| 3. "over 80%% or not" agrees (all valid steps, 80%% or more) | %d of %d = %.2f%% | %s | %s |' % (
        i3['agree'], i3['valid_stages'], 100 * i3['share'], yes(i3['minimum']), yes(i3['target'])))
    L.append('| 4. predicted limit rps within ±20%% / ±10%% (80%% or more of the scenarios with a limit) | %d / %d of %d | %s | %s |\n' % (
        i4['within_minimum'], i4['within_target'], i4['scenarios_with_limit'], yes(i4['minimum']), yes(i4['target'])))
    L.append('Numbers condition 1b is counted with (D153, D167; the goal): item 1 smaller than naive: %s; item 2 over the 5 points: %.3f; '
             'item 3 short: %d (%d of %d valid steps agree, %d needed); item 4 short: %d at the goal, %d at the lowest standard '
             '(%d of %d within ±10%%, %d within ±20%%).\n' % (
                 'yes' if one_b['item1_smaller_than_naive'] else 'no', one_b['item2_excess_points_over_5'], short3,
                 i3['agree'], i3['valid_stages'], ceil_share(i3['valid_stages']), short4_goal, short4_min,
                 i4['within_target'], i4['scenarios_with_limit'], i4['within_minimum']))
    if undecidable:
        L.append('Condition 1b: **undecidable**; no number short is given (D153).\n')
    L.append('Condition 1b counted as met (spec 7.4.4: goal met, 5 times the capture or more, a count not seen before): **%s**%s.\n' % (
        'yes' if counted else 'no', '' if counted else ' (' + '; '.join(x for x in why_not if x) + ')'))
    for s in score['scenarios']:
        lim = s['limit']['measured']
        L.append('- %s: limit rps measured %s%s; predicted point %s' % (
            s['id'], ('%.5f' % lim['rps']) if lim.get('rps') is not None else 'not measured',
            ' (%s)' % lim['method'] if lim.get('method') else (' (%s)' % lim.get('reason_code') if lim.get('reason_code') else ''),
            ('%.5f' % s['limit']['predicted']['point']) if s['limit']['predicted']['point'] is not None else 'none'))
    open(os.path.join(a.out, 'b-verdict.md'), 'w').write('\n'.join(L) + '\n')
    print('judge_b: %s (usable %d, limits measured %d, 1b counted: %s) -> %s' % (verdict, usable, with_limit, 'yes' if counted else 'no', a.out))
    return 0


if __name__ == '__main__':
    sys.exit(main())
