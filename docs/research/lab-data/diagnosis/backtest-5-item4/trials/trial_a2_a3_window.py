#!/usr/bin/env python3
"""Pencil-and-paper trials A-2 and A-3 (the "window habit") for the draft of options A, B and C. Not a result and not a test.

Reads the main tables only, by column name (never by position), plus each step's own plan.json (the schedule of the requests):
  ../90k/step_table.csv, ../170k/step_table.csv        predicted and measured utilisation, window
  ../90k/route_table.csv, ../170k/route_table.csv      predicted DB work of one request, per route
  ../90k/step_extra.csv   column zip_measured_req_time_s       the time of one ZIP request (90,000)
  ../170k/route_table.csv column req_time_measured_s           the time of one ZIP request (170,000), ZIP row
  ladder-90k/, ladder-170k/ (rung-*/plan.json)                 when each route's requests start
300,000 is left out: its main tables hold no measured time of a ZIP request (only the upper bound), and its tool was an older one.

The time of a ZIP request is the mean over the step (k6 in-flight counts, Little's formula), so it includes whatever the load
stretched it by. Nothing here is fitted: the form is fixed by arithmetic.

A-2: every ZIP request spends its predicted DB work evenly over its own time (the mean ZIP time T of the step), the other routes
     are instantaneous. Requests sent in the 60 s before the window add their share that falls inside the window, and the part
     that falls after the window is dropped. The curve of "predicted work that falls in the window" is read like the measured one
     (a straight line between the two steps that cross 80%).
A-3: a closed form with no parameter. With ZIP time T, preparation P = 60 s and window W the share of work that falls in the
     window is 1 - (T - P)^2 / (2 T W) when T > P. Applied to the predicted utilisation with T fixed at the ZIP time of the lowest
     step of the scenario (no load), and with T taken at each step.

Run (from anywhere):  python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/trials/trial_a2_a3_window.py
"""
import csv
import glob
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
ITEM4 = os.path.abspath(os.path.join(HERE, '..'))
LAB = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
SETS = ((90000, '90k', 'ladder-90k'), (170000, '170k', 'ladder-170k'))
PREP = 60.0
CORES = 2.0


def rows(path):
    with open(path, encoding='utf-8') as fh:
        return list(csv.DictReader(fh))


def num(x):
    try:
        return float(x)
    except (TypeError, ValueError):
        return None


def secs(s):
    """'35s' / '1m30s' / '1h' -> seconds."""
    total = 0.0
    for v, u in re.findall(r'([\d.]+)([hms])', s):
        total += float(v) * {'h': 3600, 'm': 60, 's': 1}[u]
    return total


def zip_times(d):
    """(run, step_rps as float) -> mean time of one ZIP request, from the main table of the count."""
    out = {}
    if d == '90k':
        for r in rows(os.path.join(ITEM4, d, 'step_extra.csv')):
            t = num(r['zip_measured_req_time_s'])
            if t is not None:
                out[(r['run'], float(r['step_rps']))] = t
    else:
        for r in rows(os.path.join(ITEM4, d, 'route_table.csv')):
            if 'export/zip' in r['route']:
                t = num(r['req_time_measured_s'])
                if t is not None:
                    out[(r['run'], float(r['step_rps']))] = t
    return out


def work_per_request(d):
    """(run, rps) -> {route: predicted DB work of one request}."""
    out = {}
    for r in rows(os.path.join(ITEM4, d, 'route_table.csv')):
        out.setdefault((r['run'], float(r['step_rps'])), {})[r['route']] = float(r['pred_work_per_req_cpu_s'])
    return out


def plans(ladder):
    """(run, rps) -> plan.json of that step."""
    out = {}
    for p in glob.glob(os.path.join(LAB, ladder, 's-*-*', 'rung-*', 'plan.json')):
        m = re.search(r'/(s-\d+)-\d+/rung-[^/]+/plan\.json$', p.replace(os.sep, '/'))
        if m is None:      # e.g. s-3-170000-INVALID-20261119 (the stopped first run), which is not used
            continue
        run = m.group(1)
        with open(p, encoding='utf-8') as fh:
            plan = json.load(fh)
        out[(run, float(plan['rps']))] = plan
    return out


def arrivals(route_plan, duration):
    tu = secs(route_plan['time_unit']) / route_plan['rate']
    a, k = [], 0
    while route_plan['start_offset_sec'] + k * tu < duration - 1e-6:
        a.append(route_plan['start_offset_sec'] + k * tu)
        k += 1
    return a


def timed_work(plan, work, t_zip):
    """Predicted DB work that falls inside the window (A-2, one mean ZIP time for the step)."""
    w_from, w_to = plan['warmup_sec'], plan['warmup_sec'] + plan['window_sec']
    heavy = max(work, key=lambda r: work[r])
    total = 0.0
    for rp in plan['routes']:
        r = rp['route']
        if r not in work:
            continue
        arr = arrivals(rp, plan['duration_sec'])
        if r == heavy:
            for a in arr:
                total += work[r] * max(0.0, min(a + t_zip, w_to) - max(a, w_from)) / t_zip
        else:
            total += work[r] * sum(1 for a in arr if w_from <= a < w_to)
    return total


def crossing(curve):
    for (r0, v0), (r1, v1) in zip(curve, curve[1:]):
        if v0 < 80 <= v1:
            return r0 + (r1 - r0) * (80 - v0) / (v1 - v0)
    return None


def main():
    print('A-2 and A-3: not a result, not a test. ZIP time = mean over the step from the main table. 300,000 left out (no measured ZIP time in its main tables).')
    for count, d, ladder in SETS:
        steps = rows(os.path.join(ITEM4, d, 'step_table.csv'))
        zt, wk, pl = zip_times(d), work_per_request(d), plans(ladder)
        by_run = {}
        for r in steps:
            by_run.setdefault(r['run'], []).append(r)
        print('== %d rows' % count)
        for run in sorted(by_run):
            st = sorted(by_run[run], key=lambda r: float(r['step_rps']))
            pts = [(float(r['step_rps']), num(r['pred_util_pct']), num(r['meas_util_pct']), float(r['window_s'])) for r in st]
            ml = crossing([(r, m) for r, _, m, _ in pts])
            if ml is None:
                continue
            base = 80 * pts[0][0] / pts[0][1]
            t0 = zt[(run, pts[0][0])]
            a2, a3_low, a3_each = [], [], []
            for r, p, m, w in pts:
                t = zt[(run, r)]
                timed = timed_work(pl[(run, r)], wk[(run, r)], t)
                a2.append((r, timed / (w * CORES) * 100))
                for tt, dst in ((t0, a3_low), (t, a3_each)):
                    dst.append((r, p * (1 - (max(tt - PREP, 0) ** 2) / (2 * tt * w))))
            def err(curve):
                c = crossing(curve)
                return None if c is None else round(c / ml - 1, 3)
            print(run, 'measured limit %.4f' % ml, '| baseline', round(base / ml - 1, 3), '| A-2 (mean ZIP time)', err(a2),
                  '| A-3 T = lowest step (%.1f s)' % t0, err(a3_low), '| A-3 T at each step', err(a3_each))
    print('not a result, not a test')


if __name__ == '__main__':
    main()
