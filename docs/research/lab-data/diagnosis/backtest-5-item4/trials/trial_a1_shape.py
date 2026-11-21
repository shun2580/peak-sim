#!/usr/bin/env python3
"""Pencil-and-paper trial A-1 (and the A-0 baseline) for the draft of options A, B and C. Not a result and not a test.

It reads only the main step tables (../90k, ../170k, ../300k /step_table.csv), by column name. It measures nothing and writes nothing.

A-0: the limit rps of the present proportional formula (80 x rps / predicted utilisation of the lowest step) against the limit rps
     read from the measured utilisation (a straight line between the two steps that cross 80%, as the scoring code does).
A-1: a one-parameter cap on "measured utilisation = f(predicted utilisation)" (smooth minimum or hyperbola), fitted on two counts
     and applied to the third, then fitted on one count and applied to another. 300,000 is an older tool and sits in the
     training set; read it as a reference only.

Run (from anywhere):  python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/trials/trial_a1_shape.py
"""
import csv
import os

HERE = os.path.dirname(os.path.abspath(__file__))
ITEM4 = os.path.abspath(os.path.join(HERE, '..'))
COUNTS = ((90000, '90k'), (170000, '170k'), (300000, '300k'))


def num(x):
    try:
        return float(x)
    except (TypeError, ValueError):
        return None


def load_series():
    """count -> run -> [(rps, predicted %, measured %)] sorted by rps."""
    out = {}
    for count, d in COUNTS:
        with open(os.path.join(ITEM4, d, 'step_table.csv'), encoding='utf-8') as fh:
            for r in csv.DictReader(fh):
                p, m = num(r['pred_util_pct']), num(r['meas_util_pct'])
                if p is None or m is None:
                    continue
                out.setdefault(count, {}).setdefault(r['run'], []).append((float(r['step_rps']), p, m))
    for runs in out.values():
        for pts in runs.values():
            pts.sort()
    return out


def measured_limit(pts):
    for (r0, _, m0), (r1, _, m1) in zip(pts, pts[1:]):
        if m0 < 80 <= m1:
            return r0 + (r1 - r0) * (80 - m0) / (m1 - m0)
    return None


def predicted_limit(pts):
    r, p = pts[0][0], pts[0][1]
    return 80 * r / p


def smooth_min(x, n):
    return x / (1 + (x / 100) ** n) ** (1 / n)


def hyperbola(x, k):
    return x / (1 + k * x / 100)


def invert(fn, par, target=80.0):
    lo, hi = 0.0, 1e4
    for _ in range(200):
        mid = (lo + hi) / 2
        if fn(mid, par) < target:
            lo = mid
        else:
            hi = mid
    return lo


def fit(series, fn, grid, counts, pred_min):
    best = None
    for par in grid:
        sse = 0.0
        for c in counts:
            for pts in series[c].values():
                for _, p, m in pts:
                    if p >= pred_min:
                        sse += (fn(p, par) - m) ** 2
        if best is None or sse < best[0]:
            best = (sse, par)
    return best[1]


def errors_for(series, count, fn, par):
    errs = []
    for run, pts in sorted(series[count].items()):
        ml = measured_limit(pts)
        if ml is None:
            continue
        a = pts[0][1] / pts[0][0]          # predicted % per rps at the lowest step
        errs.append((run, round(invert(fn, par) / a / ml - 1, 3)))
    return errs


def main():
    series = load_series()
    print('== A-0 baseline: proportional formula against the measured limit (error = predicted / measured - 1)')
    for count, _ in COUNTS:
        for run, pts in sorted(series[count].items()):
            ml, pl = measured_limit(pts), predicted_limit(pts)
            print(count, run, 'measured limit', None if ml is None else round(ml, 4), 'predicted limit', round(pl, 4),
                  'error', None if ml is None else round(pl / ml - 1, 4))
    forms = (('smooth minimum n', smooth_min, [x / 2 for x in range(2, 41)]),
             ('hyperbola k', hyperbola, [x / 100 for x in range(1, 100)]))
    all_counts = [c for c, _ in COUNTS]
    print('== A-1: fit on two counts, test on the held-out count (not a test: the held-out count was already seen)')
    for name, fn, grid in forms:
        for pred_min in (0, 40):
            print('--', name, ', fit points with predicted utilisation >=', pred_min)
            for hold in all_counts:
                train = [c for c in all_counts if c != hold]
                par = fit(series, fn, grid, train, pred_min)
                print('  held out', hold, 'fitted on', train, 'parameter', par, 'errors', errors_for(series, hold, fn, par))
    print('== A-1: fit on ONE count (300,000 not used), test on another')
    for name, fn, grid in forms:
        for tr, te in ((90000, 170000), (170000, 90000)):
            par = fit(series, fn, grid, [tr], 0)
            print(' ', name, 'fitted on', tr, 'parameter', par, 'tested on', te, errors_for(series, te, fn, par))
    print('not a result, not a test')


if __name__ == '__main__':
    main()
