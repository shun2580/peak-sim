#!/usr/bin/env python3
"""The table of conditions 1 to 3 of section 0 of the spec, counted as D153 (with D167, D215 and section 7.4.4) count them.

  python3 -I conditions.py --out <dir> [--a <a-verdict.json>] [--b <b-verdict.json>] [--causes <causes.json>]

  --a       the output of judge_a.py (condition 1a per application, and the misses of condition 3)
  --b       the output of judge_b.py (condition 1b)
  --causes  optional JSON {"<application>": {"<route>": "<where its cause is written>"}}: the misses whose cause was identified.
            Without it every miss is counted as a cause not identified, as D214 counted them

It adds no figure of its own: every number is read from the two verdict files, and the short numbers are those they already hold.
Condition 2 (a third application) is "not measured" here; it is never written as 0 (D153).
Writes `conditions.json` and `conditions.md`. Exit 0; 2 wrong usage.
"""
import argparse, json, os, sys


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', required=True)
    ap.add_argument('--a')
    ap.add_argument('--b')
    ap.add_argument('--causes')
    a = ap.parse_args()
    if not a.a and not a.b:
        print('conditions: give --a, --b or both', file=sys.stderr)
        return 2
    A = json.load(open(a.a)) if a.a else None
    B = json.load(open(a.b)) if a.b else None
    causes = json.load(open(a.causes)) if a.causes else {}
    os.makedirs(a.out, exist_ok=True)
    out, L = {}, ['# Conditions 1 to 3 of section 0 (conditions.py)\n',
                  'Counted as D153 counts them (with D167), per application for 1a (D215), and with the rule of section 7.4.4 for 1b. Every figure is read from the verdict files; nothing is typed.\n']
    short_1a_all_met = True
    if A:
        out['condition_1a'], out['condition_3'] = {}, {}
        L.append('## Condition 1a (the extrapolation test), rule B, per application\n')
        L.append('| Application | Verdict | Goal, unmarked, queries | Goal, unmarked, rows examined | Lowest standard, all, queries | Lowest standard, all, rows examined | Condition 1a |\n| --- | --- | --- | --- | --- | --- | --- |')
        for name, v in A['apps'].items():
            ca = v['condition_1a']
            cells = []
            for k in ('goal_unmarked_queries', 'goal_unmarked_rows_examined', 'minimum_all_queries', 'minimum_all_rows_examined'):
                x = ca[k]
                cells.append(('%d of %d; undecidable, no number short' % (x['inside'], x['denominator'])) if v['verdict'] == 'undecidable'
                             else '%d of %d; %d needed; short %d' % (x['inside'], x['denominator'], x['needed'], x['short']))
            state = 'undecidable' if v['verdict'] == 'undecidable' else ('met' if v['condition_1a_short_all_zero'] else 'not met')
            short_1a_all_met = short_1a_all_met and state == 'met'
            out['condition_1a'][name] = {'verdict': v['verdict'], 'numbers': ca, 'state': state}
            L.append('| %s | %s | %s | %s |' % (name, v['verdict'], ' | '.join(cells), state))
        L.append('')
    if B:
        L.append('## Condition 1b (the lab backtest), counted on the goal\n')
        o = B['condition_1b']
        out['condition_1b'] = {'verdict': B['verdict'], 'rows': B['rows'], 'multiple_of_capture': B['multiple_of_capture'],
                               'counted_as_met': B['condition_1b_counted'], 'not_counted_because': B['condition_1b_not_counted_because'], 'numbers': o}
        if B['undecidable']:
            L.append('B is **undecidable** (%s). No number short is given (D153). Condition 1b is not met.\n' % '; '.join(B['undecidable_reasons']))
        else:
            L.append('B at %s rows: **%s**. 1) item 1, smaller than the naive estimate: %s. 2) item 2, points over 5: %s. 3) item 3, agree %d of %d valid steps, short %d. '
                     '4) item 4, within ±10%% in %d of %d scenarios, short %d (lowest standard: short %d).\n' % (
                         B['rows'], B['verdict'], 'yes' if o['item1_smaller_than_naive'] else 'no', 'none' if o['item2_excess_points_over_5'] is None else '%.3f' % o['item2_excess_points_over_5'],
                         o['item3_agree'], o['item3_valid_stages'], o['item3_short'], o['item4_within_goal'], o['item4_scenarios_with_limit'],
                         o['item4_short_goal'], o['item4_short_minimum']))
        L.append('Counted as condition 1b met (7.4.4: the goal met, 5 times the capture\'s largest size or more, a count not seen before): **%s**%s. '
                 'Multiple of the capture: %s.\n' % ('yes' if B['condition_1b_counted'] else 'no',
                                                    '' if B['condition_1b_counted'] else ' (' + '; '.join(B['condition_1b_not_counted_because']) + ')',
                                                    ('%.3f' % B['multiple_of_capture']) if B['multiple_of_capture'] is not None else 'not given'))
    L.append('## Condition 2 (a third application, Bagisto)\n\nNot measured. It is not 0.\n')
    out['condition_2'] = 'not measured'
    if A:
        L.append('## Condition 3 (the remaining misses), provisional until conditions 1 and 2 are met\n')
        L.append('A miss is a route with queries or rows examined outside the goal, rule B. Remaining = misses whose cause is not identified, or that were not marked at the prediction (the union).\n')
        L.append('| Application | Misses | Marked | Unmarked | Cause identified | Remaining | Not measured (apart) | No estimate (apart) |\n| --- | --- | --- | --- | --- | --- | --- | --- |')
        for name, v in A['apps'].items():
            ident = causes.get(name, {})
            unident = [m for m in v['condition_3_misses'] if m['route'] not in ident]
            unmarked = [m for m in v['condition_3_misses'] if not m['marked']]
            remaining = {m['route'] for m in unident} | {m['route'] for m in unmarked}
            out['condition_3'][name] = {'misses': len(v['condition_3_misses']), 'marked': v['condition_3_misses_marked'],
                                        'unmarked': v['condition_3_misses_unmarked'], 'cause_identified': len(ident), 'remaining': len(remaining),
                                        'not_measured': len(v['not_measured_with_estimate']), 'no_estimate': len(v['no_estimate_routes'])}
            L.append('| %s | %d | %d | %d | %d | %d (provisional) | %d | %d |' % (
                name, len(v['condition_3_misses']), v['condition_3_misses_marked'], v['condition_3_misses_unmarked'], len(ident), len(remaining),
                len(v['not_measured_with_estimate']), len(v['no_estimate_routes'])))
        L.append('')
    json.dump(out, open(os.path.join(a.out, 'conditions.json'), 'w'), indent=1, sort_keys=True)
    open(os.path.join(a.out, 'conditions.json'), 'a').write('\n')
    open(os.path.join(a.out, 'conditions.md'), 'w').write('\n'.join(L) + '\n')
    print('conditions: written to ' + a.out)
    return 0


if __name__ == '__main__':
    sys.exit(main())
