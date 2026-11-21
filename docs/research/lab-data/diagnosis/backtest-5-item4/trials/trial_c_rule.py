#!/usr/bin/env python3
"""Pencil-and-paper trial C (a candidate "can no longer keep up" rule) for the draft of options A, B and C. Not a result and not a test.

The rule examples are "requests that finished after the window / requests sent in the window >= 10%" and ">= 20%". Both lines were
put down AFTER part of the records had been seen, so they are examples, not a rule fixed before the data (spec 7.4 opens with that
promise). The script lists, per scenario, how many requests finished after the window at each step, and which steps cross each
line, against the measured limit rps (a straight line between the two steps that cross 80% measured utilisation).

Reads only ../90k/step_table.csv and ../170k/step_table.csv, by column name. 300,000 is left out (no record of the end of a step).

Run (from anywhere):  python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/trials/trial_c_rule.py
"""
import csv
import os

HERE = os.path.dirname(os.path.abspath(__file__))
ITEM4 = os.path.abspath(os.path.join(HERE, '..'))
SETS = ((90000, '90k'), (170000, '170k'))
LINES = (0.10, 0.20)


def num(x):
    try:
        return float(x)
    except (TypeError, ValueError):
        return None


def measured_limit(pts):
    for a, b in zip(pts, pts[1:]):
        if a['meas'] < 80 <= b['meas']:
            return a['rps'] + (b['rps'] - a['rps']) * (80 - a['meas']) / (b['meas'] - a['meas'])
    return None


def main():
    print('C: not a result, not a test. The lines are examples put down after part of the records had been seen.')
    for count, d in SETS:
        with open(os.path.join(ITEM4, d, 'step_table.csv'), encoding='utf-8') as fh:
            table = list(csv.DictReader(fh))
        runs = {}
        for r in table:
            runs.setdefault(r['run'], []).append({'rps': float(r['step_rps']), 'meas': num(r['meas_util_pct']),
                                                  'sent': num(r['sent_requests']), 'after': num(r['after_window_requests'])})
        print('== %d rows' % count)
        crossed = {line: 0 for line in LINES}
        for run in sorted(runs):
            pts = sorted(runs[run], key=lambda p: p['rps'])
            limit = measured_limit(pts)
            print(run, 'finished after the window, by step:', [int(p['after']) for p in pts])
            print('   share of requests sent, by step (%):', [round(100 * p['after'] / p['sent'], 1) for p in pts])
            for line in LINES:
                hit = [p for p in pts if p['after'] / p['sent'] >= line]
                if hit:
                    crossed[line] += 1
                    first = hit[0]
                    extra = '' if limit is None else ', %.2f x the measured limit rps' % (first['rps'] / limit)
                    print('   line %d%%: first crossed at %.3g rps (measured utilisation %.1f%%)%s' % (round(line * 100), first['rps'], first['meas'], extra))
                else:
                    print('   line %d%%: not crossed at any step' % round(line * 100))
        print('   scenarios crossing the 10%% line: %d of %d; the 20%% line: %d of %d' % (crossed[0.10], len(runs), crossed[0.20], len(runs)))
    print('not a result, not a test')


if __name__ == '__main__':
    main()
