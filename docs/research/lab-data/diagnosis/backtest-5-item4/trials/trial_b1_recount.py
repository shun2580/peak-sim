#!/usr/bin/env python3
"""Pencil-and-paper trial B-1 (what a "B-light" recount of the existing records gives) for the draft of options A, B and C.
Not a result and not a test.

For every recorded step: the DB CPU between the step's first reading and the next step's first reading (the idle part taken off), divided
by the predicted DB work of ALL requests that started in the step (the 60 s of preparation included), minus 1. A step can be
recounted only when the next step starts quiet (its first interval is below 0.3 cores, so nothing is carried into it) and a next
step exists; the last step of each scenario cannot be recounted.

Reads, by column name or by name of the file:
  ../90k, ../170k /step_table.csv   predicted and measured utilisation of the step
  ../90k, ../170k /route_table.csv  predicted DB work of one request of each route
  ladder-90k/, ladder-170k/  s-N-<count>/rung-*/{cpu.txt,plan.json}   the DB CPU readings and the schedule of the requests
300,000 is left out: its tool cut the requests still running at the end of a step, so the CPU after a step is not whole.

Run (from anywhere):  python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/trials/trial_b1_recount.py
"""
import csv
import glob
import json
import os
import re
import statistics

HERE = os.path.dirname(os.path.abspath(__file__))
ITEM4 = os.path.abspath(os.path.join(HERE, '..'))
LAB = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
SETS = ((90000, '90k', 'ladder-90k'), (170000, '170k', 'ladder-170k'))
QUIET_CORES = 0.3


def rows(path):
    with open(path, encoding='utf-8') as fh:
        return list(csv.DictReader(fh))


def num(x):
    try:
        return float(x)
    except (TypeError, ValueError):
        return None


def secs(s):
    total = 0.0
    for v, u in re.findall(r'([\d.]+)([hms])', s):
        total += float(v) * {'h': 3600, 'm': 60, 's': 1}[u]
    return total


def readings(path):
    out = []
    with open(path, encoding='utf-8') as fh:
        for line in fh:
            a = line.split()
            if len(a) == 2:
                out.append((float(a[0]), int(a[1]) / 1e6))     # seconds, CPU seconds
    return out


def n_started(route_plan, duration):
    tu = secs(route_plan['time_unit']) / route_plan['rate']
    n, k = 0, 0
    while route_plan['start_offset_sec'] + k * tu < duration - 1e-6:
        n += 1
        k += 1
    return n


def scenarios(ladder):
    """run -> [(rps, cpu readings, plan)] in step order."""
    out = {}
    for sd in sorted(glob.glob(os.path.join(LAB, ladder, 's-*-*'))):
        m = re.match(r's-(\d+)-\d+$', os.path.basename(sd))
        if m is None:          # e.g. s-3-170000-INVALID-20261119 (the stopped first run), which is not used
            continue
        steps = []
        for rd in sorted(glob.glob(os.path.join(sd, 'rung-*'))):
            with open(os.path.join(rd, 'plan.json'), encoding='utf-8') as fh:
                plan = json.load(fh)
            steps.append((float(plan['rps']), readings(os.path.join(rd, 'cpu.txt')), plan))
        out['s-' + m.group(1)] = steps
    return out


def main():
    print('B-1: not a result, not a test. ratio = (DB CPU of the whole step, idle off) / (predicted work of all requests started) - 1')
    for count, d, ladder in SETS:
        steps = {(r['run'], float(r['step_rps'])): r for r in rows(os.path.join(ITEM4, d, 'step_table.csv'))}
        work = {}
        for r in rows(os.path.join(ITEM4, d, 'route_table.csv')):
            work.setdefault((r['run'], float(r['step_rps'])), {})[r['route']] = float(r['pred_work_per_req_cpu_s'])
        counted, last, other, high = [], 0, [], []
        total = 0
        over80 = []          # steps whose measured utilisation is 80% or more: (run, rps, could be recounted)
        for run, seq in sorted(scenarios(ladder).items()):
            for i, (rps, c, plan) in enumerate(seq):
                total += 1
                if num(steps[(run, rps)]['meas_util_pct']) >= 80:
                    over80.append([run, rps, False])
                if i + 1 >= len(seq):
                    last += 1
                    continue
                n = seq[i + 1][1]
                idle = (c[1][1] - c[0][1]) / (c[1][0] - c[0][0])
                next_idle = (n[1][1] - n[0][1]) / (n[1][0] - n[0][0])
                if next_idle > QUIET_CORES:
                    other.append((run, rps))
                    continue
                whole = (n[0][1] - c[0][1]) - idle * (n[0][0] - c[0][0])
                w = work[(run, rps)]
                predicted = sum(n_started(rp, plan['duration_sec']) * w[rp['route']] for rp in plan['routes'] if rp['route'] in w)
                ratio = whole / predicted - 1
                s = steps[(run, rps)]
                counted.append(ratio)
                if over80 and over80[-1][:2] == [run, rps]:
                    over80[-1][2] = True
                if num(s['pred_util_pct']) >= 60:
                    high.append((run, rps, round(num(s['pred_util_pct']), 1), round(num(s['meas_util_pct']), 1), round(ratio, 3)))
        print('== %d rows: %d steps, recounted %d, last step of a scenario %d, carry-in at the next step %s' % (count, total, len(counted), last, other))
        print('   ratio - 1: min %.3f max %.3f median %.3f' % (min(counted), max(counted), statistics.median(counted)))
        print('   steps with measured utilisation >= 80%% (run, rps, recounted?): %s' % [tuple(x) for x in over80])
        print('   recounted steps with predicted utilisation >= 60%% (run, rps, predicted %%, measured %%, ratio - 1): %s' % high)
    print('not a result, not a test')


if __name__ == '__main__':
    main()
