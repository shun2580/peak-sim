#!/usr/bin/env python3
"""Allocation of the miss of item 4 (the limit rps comes out low in all five scenarios) at 90,000, 170,000 and 300,000 rows.

A reading of records that already exist. It runs no measurement, calls nothing in the lab, uses no container, and changes no
prediction, estimate, calibration, formula, rule or score. It reads the tables of the three counts and of the conditions (the
CSV files next to it) and, for the parts the tables do not hold, the records of the steps themselves (`run.json`, `plan.json`,
`cpu.txt`, `summary.json` of each step). It writes next to itself: `allocation_by_step.csv`, `allocation.json`,
`allocation-tables.md`.

Run:  python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/allocate.py
(from anywhere; every path is found from this file's place, and the records are named from the repository root)

What it does, in the order of the output (the README next to it gives the reasoning):

1. For every recorded step it splits the difference  delta = predicted - measured  (points of the 2-core utilisation of
   that step's window) into pieces that add up to delta exactly:

     d_rounding   the prediction is made at the decided rps; the step sent a whole number of requests, and the work of those
                  requests, set against the window, is not the same number
     a_estimate   the database CPU time of the requests that were sent, as measured, against the work the prediction gave them
                  (one factor kappa for the whole step, from the step's whole CPU time; see below)
     edge_net     what the window's edges do: database work of requests sent in the window that is done after it (spill), less
                  database work of requests sent in the discarded minute that is done inside it (carry-in)
     idle         the database's own CPU when nothing is asked of it (it is in the measurement, not in the prediction)

   The edge piece is then split with a model of a request (see `edge_unloaded`): the part a ZIP request gives by its length
   alone (the same request at the time it takes in a step with a low load) and the part that is left (what a step with a high
   load adds). The model is an assumption (the work of a ZIP request is spread evenly over its time); it is checked against the
   steps where the records give the edge directly and the check is printed.

2. Sums by band of the measured utilisation, and the neighbourhood of the 80% line of each scenario.
3. The four points of the owner's reading, as numbers.
4. The size of the miss next to the length of one request over the window.
"""
import csv
import json
import math
import os
import statistics
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..', '..', '..'))
LAB = os.path.join(ROOT, 'docs', 'research', 'lab-data')
COUNTS = [(90000, '90k', 'ladder-90k'), (170000, '170k', 'ladder-170k'), (300000, '300k', 'ladder-300k')]
BANDS = [('below 60%', 0.0, 60.0), ('60% to below 90%', 60.0, 90.0), ('90% and above', 90.0, 1e9)]
LOW_STEP_BELOW = 35.0  # measured utilisation (%) under which a step is read as "no waiting": the unloaded time of a request
CORES = 2.0
CHECKS = []


def rel(p):
    return os.path.relpath(p, ROOT).replace(os.sep, '/')


def check(name, ok):
    CHECKS.append((name, bool(ok)))


def num(x):
    try:
        v = float(x)
    except (TypeError, ValueError):
        return None
    return None if math.isnan(v) else v


def read_csv(path):
    with open(path, encoding='utf-8') as fh:
        return list(csv.DictReader(fh))


def cpu_samples(path):
    out = []
    with open(path, encoding='utf-8') as fh:
        for line in fh:
            a = line.split()
            if len(a) == 2:
                out.append((float(a[0]), float(a[1])))
    return out


def interp(samples, t):
    """usage_usec at epoch t, linear between the neighbouring readings (the way the tool reads the window's ends)."""
    for (t0, u0), (t1, u1) in zip(samples, samples[1:]):
        if t0 <= t <= t1:
            return u0 + (u1 - u0) * (t - t0) / (t1 - t0)
    raise ValueError('t outside the readings')


def period_s(r):
    unit = float(r['time_unit'].rstrip('s'))
    return unit / float(r['rate'])


def arrivals(r, duration):
    t, out = float(r['start_offset_sec']), []
    p = period_s(r)
    while t < duration - 1e-9:
        out.append(t)
        t += p
    return out


def median(v):
    v = [x for x in v if x is not None]
    return statistics.median(v) if v else None


def rank(v):
    s = sorted(range(len(v)), key=lambda i: v[i])
    r = [0.0] * len(v)
    i = 0
    while i < len(s):
        j = i
        while j + 1 < len(s) and v[s[j + 1]] == v[s[i]]:
            j += 1
        for k in range(i, j + 1):
            r[s[k]] = (i + j) / 2 + 1
        i = j + 1
    return r


def spearman(x, y):
    if len(x) < 3:
        return None
    rx, ry = rank(x), rank(y)
    mx, my = statistics.mean(rx), statistics.mean(ry)
    sx = math.sqrt(sum((a - mx) ** 2 for a in rx))
    sy = math.sqrt(sum((b - my) ** 2 for b in ry))
    if sx == 0 or sy == 0:
        return None
    return sum((a - mx) * (b - my) for a, b in zip(rx, ry)) / (sx * sy)


def edge_model(zip_arrivals, T, warmup, duration, w):
    """Work of one heavy route's requests spread evenly over the time T that one request takes. Returns (spill, carry) in CPU s:
    spill = work done after the window by requests sent inside it; carry = work done inside the window by requests sent in the
    discarded minute. Times are seconds from the start of the load tool; the window is [warmup, duration)."""
    spill = carry = 0.0
    for t in zip_arrivals:
        end = t + T
        if t >= warmup:
            spill += w * max(0.0, end - duration) / T
        else:
            carry += w * max(0.0, min(end, duration) - max(t, warmup)) / T
    return spill, carry


# ---------------------------------------------------------------------------------------------------------------------------
steps = []  # one dict per recorded step
for count, short, ladder_dir in COUNTS:
    base = os.path.join(HERE, short)
    st_rows = read_csv(os.path.join(base, 'step_table.csv'))
    rt_rows = read_csv(os.path.join(base, 'route_table.csv'))
    cov_rows = [r for r in read_csv(os.path.join(HERE, 'conditions', 'step_cpu_coverage.csv')) if int(r['count']) == count]
    tail = {}
    if count == 300000:
        for r in read_csv(os.path.join(base, 'tail_table.csv')):
            tail[(r['run'], float(r['step_rps']))] = r
    work_by = {}  # (run, rps) -> {route: (sent, w)}
    for r in rt_rows:
        work_by.setdefault((r['run'], float(r['step_rps'])), {})[r['route']] = (int(r['sent_requests']), float(r['pred_work_per_req_cpu_s']))
    cov = {(r['run'], float(r['step_rps'])): r for r in cov_rows}
    lad = os.path.join(LAB, ladder_dir)
    for sr in st_rows:
        run, rps = sr['run'], float(sr['step_rps'])
        sdir = os.path.join(lad, '%s-%d' % (run, count))
        rung = [d for d in os.listdir(sdir) if d.startswith('rung-') and abs(float(d.split('-', 2)[2]) - rps) < 1e-9]
        assert len(rung) == 1, (count, run, rps, rung)
        rdir = os.path.join(sdir, rung[0])
        runj = json.load(open(os.path.join(rdir, 'run.json')))
        plan = json.load(open(os.path.join(rdir, 'plan.json')))
        summ = json.load(open(os.path.join(rdir, 'summary.json')))
        cs = cpu_samples(os.path.join(rdir, 'cpu.txt'))
        c = cov[(run, rps)]
        d = dict(count=count, short=short, run=run, rps=rps, rdir=rel(rdir))
        d['window_s'] = float(sr['window_s'])
        d['pred_pt'] = float(sr['pred_util_pct'])
        d['meas_pt'] = float(sr['meas_util_pct'])
        d['diff_pt'] = float(sr['diff_pt'])
        d['table_meas_pt'], d['table_diff_pt'] = d['meas_pt'], d['diff_pt']
        d['sent'] = int(sr['sent_requests'])
        d['pred_work_sent'] = float(sr['pred_work_cpu_s'])
        d['after_window_requests'] = int(sr['after_window_requests'])
        d['max_end_delay'] = sr['max_end_of_step_delay_s']
        d['weighted_ratio_upper'] = num(sr['weighted_req_over_window'])
        d['summary_max_s'] = summ['duration_ms']['max'] / 1000.0
        d['longest_over_window'] = d['summary_max_s'] / d['window_s']
        d['interrupted'] = int(c['k6_interrupted_iterations'])
        d['window_cpu_s'] = float(c['window_cpu_s'])
        # the measured utilisation from the window's CPU time (the tables print it rounded)
        d['meas_pt'] = d['window_cpu_s'] / (CORES * d['window_s']) * 100.0
        d['diff_pt'] = d['pred_pt'] - d['meas_pt']
        check('measured utilisation from the window CPU time equals the table (%s %s %s)' % (count, run, rps), abs(d['meas_pt'] - d['table_meas_pt']) < 0.01)
        check('difference equals the table (%s %s %s)' % (count, run, rps), abs(d['diff_pt'] - d['table_diff_pt']) < 0.01)
        d['reach_s'] = float(c['cpu_readings_reach_after_window_s'])
        d['cpu_after_read_s'] = float(c['cpu_after_window_read_s'])
        d['spill_cpu_s'] = num(c['spill_cpu_s'])
        d['spill_basis'] = c['spill_cpu_basis']
        d['spill_upper_s'] = num(c['spill_cpu_upper_bound_s'])
        d['gap_s'] = num(c['next_step_gap_s'])
        d['spill_missing'] = c['spill_missing']
        # the records name the window by epoch; the load tool started warmup seconds before it
        warm = float(plan['warmup_sec'])
        dur = float(plan['duration_sec'])
        d['warmup_s'], d['duration_s'] = warm, dur
        wf = runj['window_epoch']['from']
        d['t_first'] = cs[0][0]
        d['disc_span_s'] = wf - cs[0][0]
        d['disc_cpu_s'] = (interp(cs, wf) - cs[0][1]) / 1e6
        # arrivals per route from the plan: sent in the discarded minute, and the check against the counts the table holds
        wk = work_by[(run, rps)]
        n_disc, n_win, zip_route = {}, {}, None
        for pr in plan['routes']:
            a = arrivals(pr, dur)
            n_disc[pr['route']] = sum(1 for t in a if t < warm)
            n_win[pr['route']] = sum(1 for t in a if t >= warm)
            check('plan arrivals in the window equal planned_window_requests (%s %s %s %s)' % (count, run, rps, pr['route'][-12:]),
                  n_win[pr['route']] == pr['planned_window_requests'])
            check('plan arrivals in the window equal the sent count of the route table (%s %s %s %s)' % (count, run, rps, pr['route'][-12:]),
                  n_win[pr['route']] == wk[pr['route']][0])
            if pr['route'].endswith('/export/zip'):
                zip_route = pr
        d['n_disc_total'] = sum(n_disc.values())
        d['pred_work_disc'] = sum(n_disc[r] * wk[r][1] for r in n_disc)
        d['pred_work_sent_check'] = sum(n_win[r] * wk[r][1] for r in n_win)
        check('sum of sent x work per request equals pred_work_cpu_s of the step table (%s %s %s)' % (count, run, rps),
              abs(d['pred_work_sent_check'] - d['pred_work_sent']) < 0.01)
        d['zip_w'] = wk[zip_route['route']][1]
        d['zip_arrivals'] = arrivals(zip_route, dur)
        d['zip_n_window'] = n_win[zip_route['route']]
        d['zip_share_of_work'] = (n_win[zip_route['route']] * d['zip_w']) / d['pred_work_sent_check'] if d['pred_work_sent_check'] else None
        if count == 300000:
            t = tail[(run, rps)]
            d['pre_start_rate'] = float(t['pre_start_rate_cores'])
        else:
            d['pre_start_rate'] = None
        steps.append(d)

# the idle rate of the database, per count: the readings after the window of the steps where nothing was running at the window's end
idle = {}
for count, short, _ in COUNTS:
    v = [s['cpu_after_read_s'] / s['reach_s'] for s in steps if s['count'] == count and s['after_window_requests'] == 0 and s['interrupted'] == 0]
    idle[count] = dict(rate_cores=statistics.median(v), n=len(v), min=min(v), max=max(v))

# the unloaded time of one ZIP request, per count and scenario: the longest request of the steps whose measured utilisation is low
for s in steps:
    pool = [x['summary_max_s'] for x in steps if x['count'] == s['count'] and x['run'] == s['run'] and x['meas_pt'] < LOW_STEP_BELOW]
    s['T0'] = statistics.median(pool) if pool else None
    s['T0_n'] = len(pool)

# ---------------------------------------------------------------------------------------------------------------------------
for s in steps:
    ir = idle[s['count']]['rate_cores']
    W = s['window_s']
    s['idle_pt'] = ir / CORES * 100.0
    s['psent_pt'] = s['pred_work_sent'] / (CORES * W) * 100.0
    s['d_rounding'] = s['pred_pt'] - s['psent_pt']
    s['disc_net_cpu_s'] = s['disc_cpu_s'] - ir * s['disc_span_s']
    s['window_net_cpu_s'] = s['window_cpu_s'] - ir * W
    # the span the spill reading covers (for taking the idle off it)
    if s['spill_cpu_s'] is not None:
        span = s['reach_s'] + (s['gap_s'] if s['spill_basis'].startswith('read after the window plus the gap') else 0.0)
        s['spill_span_s'] = span
        s['spill_net_cpu_s'] = s['spill_cpu_s'] - ir * span
    else:
        s['spill_span_s'] = None
        s['spill_net_cpu_s'] = None
    clean = s['count'] != 300000 or (s['pre_start_rate'] is not None and s['pre_start_rate'] < 0.1)
    s['clean_start'] = clean
    if s['spill_net_cpu_s'] is not None and clean:
        tot = s['disc_net_cpu_s'] + s['window_net_cpu_s'] + s['spill_net_cpu_s']
        s['kappa'] = tot / (s['pred_work_sent'] + s['pred_work_disc'])
        s['status'] = 'measured'
    else:
        s['kappa'] = None
        s['status'] = None

# kappa to use where the step's whole CPU time cannot be read: the median of the same scenario's measured steps (or of the count)
for s in steps:
    if s['status'] == 'measured':
        s['kappa_used'] = s['kappa']
        s['kappa_lo'] = s['kappa_hi'] = s['kappa']
        continue
    pool = [x['kappa'] for x in steps if x['count'] == s['count'] and x['run'] == s['run'] and x['kappa'] is not None]
    src = 'scenario'
    if not pool:
        pool = [x['kappa'] for x in steps if x['count'] == s['count'] and x['kappa'] is not None]
        src = 'count'
    s['kappa_used'] = statistics.median(pool)
    s['kappa_lo'], s['kappa_hi'] = min(pool), max(pool)
    s['kappa_src'] = src
    s['kappa_pool_n'] = len(pool)
    s['status'] = 'residual' if s['clean_start'] else 'not_allocated'

for s in steps:
    W = s['window_s']
    k = s['kappa_used']
    unit = CORES * W / 100.0  # CPU seconds that make one point of this step
    s['a_estimate'] = (1.0 - k) * s['psent_pt']
    s['carry_pt'] = (k * s['pred_work_disc'] - s['disc_net_cpu_s']) / unit if s['clean_start'] else None
    if s['status'] == 'measured':
        s['spill_pt'] = s['spill_net_cpu_s'] / unit
        s['edge_net'] = s['spill_pt'] - s['carry_pt']
    elif s['status'] == 'residual':
        s['edge_net'] = s['diff_pt'] - s['d_rounding'] - s['a_estimate'] + s['idle_pt']
        s['spill_pt'] = s['edge_net'] + s['carry_pt']
    else:
        s['edge_net'] = None
        s['spill_pt'] = None
    s['idle'] = -s['idle_pt']
    # the model of the edge: ZIP requests spread evenly over the unloaded time
    if s['T0']:
        sp, ca = edge_model(s['zip_arrivals'], s['T0'], s['warmup_s'], s['duration_s'], s['zip_w'])
        s['model_spill_pt'] = k * sp / unit
        s['model_carry_pt'] = k * ca / unit
        s['edge_unloaded'] = s['model_spill_pt'] - s['model_carry_pt']
    else:
        s['model_spill_pt'] = s['model_carry_pt'] = None
        s['edge_unloaded'] = None
    if s['edge_net'] is not None and s['edge_unloaded'] is not None:
        s['edge_extra'] = s['edge_net'] - s['edge_unloaded']
        check('the pieces add up to the difference (%s %s %s)' % (s['count'], s['run'], s['rps']),
              abs(s['d_rounding'] + s['a_estimate'] + s['edge_unloaded'] + s['edge_extra'] + s['idle'] - s['diff_pt']) < 1e-6)
    else:
        s['edge_extra'] = None
    # what is left when nothing of the window's edges is split (the steps of the 300,000-row records that cannot be read)
    s['rest_pt'] = s['diff_pt'] - s['d_rounding'] + s['idle_pt']  # = a + edge, together

# check of the model against the steps where the records give the edge directly, at a low load
val = []
for s in steps:
    if s['status'] == 'measured' and s['meas_pt'] < LOW_STEP_BELOW and s['T0']:
        val.append(dict(count=s['count'], run=s['run'], rps=s['rps'], meas_pt=s['meas_pt'], model_spill_cpu=s['model_spill_pt'] * CORES * s['window_s'] / 100.0,
                        record_spill_cpu=s['spill_net_cpu_s'], model_carry_cpu=s['model_carry_pt'] * CORES * s['window_s'] / 100.0,
                        record_carry_cpu=s['kappa_used'] * s['pred_work_disc'] - s['disc_net_cpu_s']))
# the same for the edge (spill minus carry) by count, steps with a model edge above 1 CPU second only
val_by_count = {}
for count, _, _ in COUNTS:
    v = [x for x in val if x['count'] == count]
    if v:
        diffs = [(x['model_spill_cpu'] - x['model_carry_cpu']) - (x['record_spill_cpu'] - x['record_carry_cpu']) for x in v]
        val_by_count[count] = dict(n=len(v), edge_model_minus_record_cpu_s=dict(min=min(diffs), max=max(diffs), median=statistics.median(diffs)),
                                   sum_record_spill=sum(x['record_spill_cpu'] for x in v), sum_model_spill=sum(x['model_spill_cpu'] for x in v))

# work that was already running when the step started (an earlier step's requests): the database's CPU in the discarded minute,
# less the work the step's own requests of that minute do in it (the model of the edge, with kappa of the count or scenario)
for s in steps:
    if s['T0']:
        sp, ca = edge_model(s['zip_arrivals'], s['T0'], s['warmup_s'], s['duration_s'], s['zip_w'])
        own = s['kappa_used'] * (s['pred_work_disc'] - ca)
        s['incoming_cpu_s'] = s['disc_net_cpu_s'] - own
        s['incoming_over_window_cpu'] = s['incoming_cpu_s'] / s['window_net_cpu_s']
    else:
        s['incoming_cpu_s'] = s['incoming_over_window_cpu'] = None
incoming_stats = {}
for count, _, _ in COUNTS:
    v = [s['incoming_cpu_s'] for s in steps if s['count'] == count and s['clean_start'] and s['incoming_cpu_s'] is not None]
    w = [s['incoming_cpu_s'] for s in steps if s['count'] == count and not s['clean_start'] and s['incoming_cpu_s'] is not None]
    incoming_stats[count] = dict(clean_n=len(v), clean_min=min(v) if v else None, clean_max=max(v) if v else None, clean_median=median(v),
                                 other_n=len(w), other_min=min(w) if w else None, other_max=max(w) if w else None, other_median=median(w))

# ---------------------------------------------------------------------------------------------------------------------------
# sensitivity of the residual steps to the factor kappa, and the check of their edge against the upper bound the records give
for s in steps:
    if s['status'] != 'residual':
        continue
    unit = CORES * s['window_s'] / 100.0
    rng = []
    for k in (s['kappa_lo'], s['kappa_hi']):
        a = (1.0 - k) * s['psent_pt']
        carry = (k * s['pred_work_disc'] - s['disc_net_cpu_s']) / unit
        edge_net = s['diff_pt'] - s['d_rounding'] - a + s['idle_pt']
        sp, ca = edge_model(s['zip_arrivals'], s['T0'], s['warmup_s'], s['duration_s'], s['zip_w']) if s['T0'] else (0.0, 0.0)
        eu = k * (sp - ca) / unit
        rng.append(dict(kappa=k, a_estimate=a, edge_net=edge_net, edge_extra=edge_net - eu, spill_net_cpu_s=(edge_net + carry) * unit))
    s['sens'] = rng
    s['spill_resid_cpu_s'] = s['spill_pt'] * unit
    s['spill_resid_within_upper'] = (s['spill_resid_cpu_s'] <= s['spill_upper_s'] + 1e-6) if s['spill_upper_s'] is not None else None
    if s['spill_upper_s'] is not None and s['T0']:
        sp, ca = edge_model(s['zip_arrivals'], s['T0'], s['warmup_s'], s['duration_s'], s['zip_w'])
        k0 = s['kappa_used']
        s['edge_extra_cap_by_bound'] = (s['spill_upper_s'] / unit - s['carry_pt']) - k0 * (sp - ca) / unit
    else:
        s['edge_extra_cap_by_bound'] = None

# ---------------------------------------------------------------------------------------------------------------------------
# trials of the three proposed repairs on records already seen. NOT results and NOT tests: every count here was seen when the
# form was chosen. They are arithmetic on the records, to show the size of what each proposal would touch.
#
# Trial A: the utilisation a step would show if a ZIP request drew a fixed share of the database's CPU while it ran
# (rate r cores, the rate that one request is seen to draw in the steps of low load), the database could give at most 2 cores
# to all the requests together (shared equally when they ask for more), and the window were read as the tool reads it. No
# kappa, no fit to the step: the request's work (the prediction's), the arrival times (the plan), r (from 90,000 rows only) and
# the idle rate. The other routes' work is put in the window at its arrival.
def ps_work_in_window(arrs, w, r, cap, t0, t1):
    t = 0.0
    todo = sorted(arrs)
    i = 0
    active = []  # remaining work of each running request
    done = 0.0
    while i < len(todo) or active:
        n = len(active)
        rate = min(r, cap / n) if n else 0.0
        t_arr = todo[i] if i < len(todo) else float('inf')
        t_fin = t + min(active) / rate if n else float('inf')
        t_next = min(t_arr, t_fin)
        dt = t_next - t
        lo, hi = max(t, t0), min(t_next, t1)
        if hi > lo and n:
            done += n * rate * (hi - lo)
        if n:
            active = [x - rate * dt for x in active]
        t = t_next
        if t_fin <= t_arr:
            active = [x for x in active if x > 1e-9]
        else:
            active.append(w)
            i += 1
    return done


rate_by_count = {}
for count, _, _ in COUNTS:
    v = []
    for run in ['s-1', 's-2', 's-3', 's-4', 's-5']:
        x = [s for s in steps if s['count'] == count and s['run'] == run and s['T0']]
        if x:
            v.append(x[0]['zip_w'] / x[0]['T0'])
    rate_by_count[count] = dict(median=statistics.median(v), min=min(v), max=max(v), n=len(v))
R_FIXED = rate_by_count[90000]['median']
for s in steps:
    zip_in_win = ps_work_in_window(s['zip_arrivals'], s['zip_w'], R_FIXED, CORES, s['warmup_s'], s['duration_s'])
    ir = idle[s['count']]['rate_cores']
    other = s['pred_work_sent'] - s['zip_n_window'] * s['zip_w']
    s['stretch'] = s['summary_max_s'] / s['T0'] if s['T0'] else None
    s['trialA_pt'] = (zip_in_win + other + ir * s['window_s']) / (CORES * s['window_s']) * 100.0
    s['trialA_err_pt'] = s['trialA_pt'] - s['meas_pt']
    s['pred_err_pt'] = s['pred_pt'] - s['meas_pt']
    # trial B: the work of the requests sent in the window, counted whole (the wait read), idle kept as the tool keeps it
    if s['status'] == 'measured':
        unit = CORES * s['window_s'] / 100.0
        s['trialB_pt'] = (s['window_net_cpu_s'] - (s['kappa_used'] * s['pred_work_disc'] - s['disc_net_cpu_s']) + s['spill_net_cpu_s']) / unit + s['idle_pt']
    else:
        s['trialB_pt'] = None


def limit_cross(sc, key):
    """The rps at which the series `key` crosses 80% (straight line between the bounding steps), or None."""
    for a, b in zip(sc, sc[1:]):
        if a[key] is not None and b[key] is not None and a[key] < 80.0 <= b[key]:
            return a['rps'] + (80.0 - a[key]) / (b[key] - a[key]) * (b['rps'] - a['rps'])
    return None


trials = []
for count, _, _ in COUNTS:
    for run in ['s-1', 's-2', 's-3', 's-4', 's-5']:
        sc = sorted([s for s in steps if s['count'] == count and s['run'] == run], key=lambda x: x['rps'])
        lm = limit_cross(sc, 'meas_pt')
        lp = 80.0 * sc[-1]['rps'] / sc[-1]['pred_pt']
        la = limit_cross(sc, 'trialA_pt')
        measB = [s for s in sc if s['trialB_pt'] is not None]
        lb = 80.0 * measB[-1]['rps'] / measB[-1]['trialB_pt'] if measB else None
        top = sc[-1]
        trials.append(dict(count=count, run=run, measured_limit=lm, predicted_limit=lp, trialA_limit=la, trialB_limit=lb,
                           trialB_from_rps=measB[-1]['rps'] if measB else None, trialB_from_meas_pt=measB[-1]['meas_pt'] if measB else None,
                           pred_err_pct=None if lm is None else (lp - lm) / lm * 100.0, trialA_err_pct=None if (lm is None or la is None) else (la - lm) / lm * 100.0,
                           trialB_err_pct=None if (lm is None or lb is None) else (lb - lm) / lm * 100.0,
                           pred_vs_trialB_pct=None if lb is None else (lp - lb) / lb * 100.0))
trialA_stats = {}
for count, _, _ in COUNTS:
    cs = [s for s in steps if s['count'] == count]
    for name, lo, hi in BANDS:
        rows = [s for s in cs if lo <= s['meas_pt'] < hi]
        if rows:
            trialA_stats[(count, name)] = dict(n=len(rows), pred_mean_abs=statistics.mean(abs(s['pred_err_pt']) for s in rows), trialA_mean_abs=statistics.mean(abs(s['trialA_err_pt']) for s in rows),
                                               pred_mean=statistics.mean(s['pred_err_pt'] for s in rows), trialA_mean=statistics.mean(s['trialA_err_pt'] for s in rows))

# Trial C: examples of "the load was not got through" rules read from the end-of-step record, with the share of the requests sent in
# the window that finished after it (`requests_finished_after_window` over `sent`). The thresholds are examples, not a choice: the
# rps at which each first reaches them is shown for three, so that the dependence on the threshold is visible.
C_SHARES = [0.05, 0.10, 0.20]
S_STRETCH = [1.10, 1.25, 1.50]
trialC = []
for count, _, _ in COUNTS:
    for run in ['s-1', 's-2', 's-3', 's-4', 's-5']:
        sc = sorted([s for s in steps if s['count'] == count and s['run'] == run], key=lambda x: x['rps'])
        row = dict(count=count, run=run)
        for th in C_SHARES:
            hit = [s for s in sc if s['sent'] and s['after_window_requests'] / s['sent'] >= th]
            row['first_rps_at_%d' % round(th * 100)] = hit[0]['rps'] if hit else None
            row['first_meas_at_%d' % round(th * 100)] = hit[0]['meas_pt'] if hit else None
        # the stretch of the longest request of the step against the longest request of the steps of low load (the same scenario)
        for th in S_STRETCH:
            hit = [s for s in sc if s['T0'] and s['summary_max_s'] / s['T0'] >= th]
            row['stretch_first_rps_at_%s' % ('%.2f' % th).replace('.', '_')] = hit[0]['rps'] if hit else None
            row['stretch_first_meas_at_%s' % ('%.2f' % th).replace('.', '_')] = hit[0]['meas_pt'] if hit else None
        trialC.append(row)

# ---------------------------------------------------------------------------------------------------------------------------
# aggregation
PARTS = ['diff_pt', 'd_rounding', 'a_estimate', 'edge_unloaded', 'edge_extra', 'idle']


def band_of(m):
    for name, lo, hi in BANDS:
        if lo <= m < hi:
            return name


def aggregate(rows):
    out = dict(n=len(rows))
    for p in PARTS + ['spill_pt', 'carry_pt', 'rest_pt']:
        v = [r[p] for r in rows]
        out[p] = None if any(x is None for x in v) or not v else sum(v)
    return out


agg = {}
for count, short, _ in COUNTS:
    cs = [s for s in steps if s['count'] == count]
    agg[count] = {}
    for name, lo, hi in BANDS:
        rows = [s for s in cs if lo <= s['meas_pt'] < hi]
        agg[count][name] = dict(all=aggregate(rows) if rows else None,
                                measured=aggregate([s for s in rows if s['status'] == 'measured']) if rows else None,
                                residual=aggregate([s for s in rows if s['status'] == 'residual']) if rows else None,
                                not_allocated=dict(n=len([s for s in rows if s['status'] == 'not_allocated']),
                                                   diff_pt=sum(s['diff_pt'] for s in rows if s['status'] == 'not_allocated'),
                                                   rest_pt=sum(s['rest_pt'] for s in rows if s['status'] == 'not_allocated'),
                                                   d_rounding=sum(s['d_rounding'] for s in rows if s['status'] == 'not_allocated'),
                                                   idle=sum(s['idle'] for s in rows if s['status'] == 'not_allocated')))

# neighbourhood of the 80% line of each scenario: the two steps that bound the measured crossing
near = []
limit_rows = []
for count, short, _ in COUNTS:
    for run in ['s-1', 's-2', 's-3', 's-4', 's-5']:
        sc = sorted([s for s in steps if s['count'] == count and s['run'] == run], key=lambda x: x['rps'])
        pair = None
        for a, b in zip(sc, sc[1:]):
            if a['meas_pt'] < 80.0 <= b['meas_pt']:
                pair = (a, b)
                break
        if pair:
            a, b = pair
            lm = a['rps'] + (80.0 - a['meas_pt']) / (b['meas_pt'] - a['meas_pt']) * (b['rps'] - a['rps'])
            lp = 80.0 * b['rps'] / b['pred_pt']
            for x in (a, b):
                x['meas_norm_pt'] = x['meas_pt'] * x['pred_pt'] / x['psent_pt']
            ln = a['rps'] + (80.0 - a['meas_norm_pt']) / (b['meas_norm_pt'] - a['meas_norm_pt']) * (b['rps'] - a['rps'])
            limit_rows.append(dict(count=count, run=run, lower_rps=a['rps'], upper_rps=b['rps'], measured_limit_rps=lm, predicted_limit_rps=lp,
                                   error_pct=(lp - lm) / lm * 100.0, measured_limit_normalised_rps=ln, error_normalised_pct=(lp - ln) / ln * 100.0,
                                   window_upper_s=b['window_s'], T0_s=b['T0'], T0_over_window=b['T0'] / b['window_s'], upper_longest_over_window=b['longest_over_window']))
            for s in pair:
                near.append(dict(count=count, run=run, rps=s['rps'], meas_pt=s['meas_pt'], pred_pt=s['pred_pt'], diff_pt=s['diff_pt'], status=s['status'],
                                 d_rounding=s['d_rounding'], a_estimate=s['a_estimate'], edge_unloaded=s['edge_unloaded'], edge_extra=s['edge_extra'], idle=s['idle'],
                                 weighted_ratio_upper=s['weighted_ratio_upper']))
        else:
            top = sc[-1]
            limit_rows.append(dict(count=count, run=run, lower_rps=None, upper_rps=None, measured_limit_rps=None, predicted_limit_rps=80.0 * top['rps'] / top['pred_pt'],
                                   error_pct=None, note='no valid step at or above 80% measured'))

# the owner's four points
points = {}
for count, short, _ in COUNTS:
    cs = [s for s in steps if s['count'] == count]
    low = [s for s in cs if s['meas_pt'] < 60.0]
    hi = [s for s in cs if s['meas_pt'] >= 70.0]
    p1 = dict(n_low=len(low), diff_min=min(s['diff_pt'] for s in low), diff_max=max(s['diff_pt'] for s in low), n_pos=sum(1 for s in low if s['diff_pt'] > 0),
              n_neg=sum(1 for s in low if s['diff_pt'] < 0), diff_mean=statistics.mean(s['diff_pt'] for s in low),
              diff_plus_idle_min=min(s['diff_pt'] + s['idle_pt'] for s in low), diff_plus_idle_max=max(s['diff_pt'] + s['idle_pt'] for s in low),
              kappa_low=[s['kappa'] for s in low if s['kappa'] is not None],
              n_low_over_60=0)
    p1['kappa_min'] = min(p1['kappa_low']) if p1['kappa_low'] else None
    p1['kappa_max'] = max(p1['kappa_low']) if p1['kappa_low'] else None
    p1['kappa_median'] = median(p1['kappa_low'])
    del p1['kappa_low']
    # the bend: measured utilisation per rps against the same at low load (by scenario), and where it falls by more than 5%
    bend = []
    for run in ['s-1', 's-2', 's-3', 's-4', 's-5']:
        sc = sorted([s for s in cs if s['run'] == run], key=lambda x: x['rps'])
        ref = [(s['meas_pt'] - s['idle_pt']) / s['rps'] for s in sc if s['meas_pt'] < LOW_STEP_BELOW]
        refp = [s['pred_pt'] / s['rps'] for s in sc if s['pred_pt'] < LOW_STEP_BELOW]
        if not ref:
            continue
        m0, p0 = statistics.median(ref), statistics.median(refp)
        first = None
        series = []
        for s in sc:
            rm = ((s['meas_pt'] - s['idle_pt']) / s['rps']) / m0
            series.append((s['rps'], s['meas_pt'], round(rm, 4)))
            if first is None and rm < 0.95 and s['meas_pt'] >= LOW_STEP_BELOW:
                first = dict(rps=s['rps'], meas_pt=s['meas_pt'], pred_pt=s['pred_pt'], ratio=rm)
        bend.append(dict(run=run, ref_meas_per_rps=m0, ref_pred_per_rps=p0, first_below_95pct=first, series=series))
    p2 = dict(n_hi=len(hi), diff_hi=[(s['run'], s['rps'], round(s['meas_pt'], 2), round(s['diff_pt'], 2)) for s in hi], bend=bend)
    points[count] = dict(low=p1, high=p2)

# the size of the miss next to one request over the window
relation = {}
for count, short, _ in COUNTS:
    cs = [s for s in steps if s['count'] == count and s['weighted_ratio_upper'] is not None]
    relation[count] = dict(
        n=len(cs),
        spearman_all=spearman([s['diff_pt'] for s in cs], [s['weighted_ratio_upper'] for s in cs]),
        spearman_longest_all=spearman([s['diff_pt'] for s in cs], [s['longest_over_window'] for s in cs]),
        spearman_longest_60_up=spearman([s['diff_pt'] for s in cs if s['meas_pt'] >= 60.0], [s['longest_over_window'] for s in cs if s['meas_pt'] >= 60.0]),
        spearman_60_up=spearman([s['diff_pt'] for s in cs if s['meas_pt'] >= 60.0], [s['weighted_ratio_upper'] for s in cs if s['meas_pt'] >= 60.0]),
        n_60_up=len([s for s in cs if s['meas_pt'] >= 60.0]))

# ---------------------------------------------------------------------------------------------------------------------------
# outputs
fields = ['count', 'run', 'rps', 'window_s', 'meas_pt', 'pred_pt', 'diff_pt', 'status', 'd_rounding', 'a_estimate', 'edge_unloaded', 'edge_extra', 'idle',
          'spill_pt', 'carry_pt', 'rest_pt', 'kappa_used', 'kappa_lo', 'kappa_hi', 'T0', 'weighted_ratio_upper', 'longest_over_window', 'after_window_requests', 'zip_share_of_work', 'trialA_pt', 'trialB_pt', 'stretch']
with open(os.path.join(HERE, 'allocation_by_step.csv'), 'w', newline='', encoding='utf-8') as fh:
    w = csv.writer(fh)
    w.writerow(fields)
    for s in steps:
        w.writerow([('NA' if s.get(f) is None else ('%.6g' % s[f] if isinstance(s[f], float) else s[f])) for f in fields])

out = dict(idle=idle, validation_of_model=val_by_count, validation_steps=val, aggregate=agg, near_80=near, limits=limit_rows, owner_points=points, relation=relation, trials=trials, trialA_stats={'%d|%s' % k: v for k, v in trialA_stats.items()}, trialC=trialC, request_rate_by_count=rate_by_count, trial_rate_used=R_FIXED, incoming=incoming_stats,
           residual_checks=[dict(count=s['count'], run=s['run'], rps=s['rps'], spill_resid_cpu_s=s['spill_resid_cpu_s'], spill_upper_s=s['spill_upper_s'], within=s['spill_resid_within_upper'], edge_extra=s['edge_extra'], edge_extra_cap_by_bound=s['edge_extra_cap_by_bound'], sens=s['sens']) for s in steps if s['status'] == 'residual'],
           checks=[dict(name=n, ok=ok) for n, ok in CHECKS],
           method=dict(low_step_below_pct=LOW_STEP_BELOW, cores=CORES, bands=[b[0] for b in BANDS]))
with open(os.path.join(HERE, 'allocation.json'), 'w', encoding='utf-8') as fh:
    json.dump(out, fh, indent=1, sort_keys=True, default=lambda o: None)
    fh.write('\n')


def f(x, d=2):
    return 'NA' if x is None else ('%+.*f' % (d, x))


def g(x, d=2):
    return 'NA' if x is None else ('%.*f' % (d, x))


md = ['# Allocation tables (written by `allocate.py`; nothing here is a prediction, a measurement or a score)', '']
md.append('Unit: points of the 2-core utilisation of the step\'s own window. A piece is its contribution to `diff = predicted - measured`; the pieces of a step add up to `diff`.')
md.append('')
md.append('## Idle rate of the database (taken off the measured side)')
md.append('')
md.append('| rows | idle CPU (cores, median) | steps read | min | max | in points of utilisation |')
md.append('|---|---|---|---|---|---|')
for count, _, _ in COUNTS:
    i = idle[count]
    md.append('| %d | %.4f | %d | %.4f | %.4f | %.2f |' % (count, i['rate_cores'], i['n'], i['min'], i['max'], i['rate_cores'] / CORES * 100))
md.append('')
md.append('## Allocation by band of the measured utilisation')
md.append('')
md.append('Sums over the steps of the band, in points. `rounding` (the whole number of requests sent against the decided rps), `estimate` (one request\'s work), `edge, request length alone` and `edge, added by load` (the window\'s edges, split by the model), `idle`. Shares are of the sum of `diff` and are printed only when that sum is 3 points or more in size (below that a share is not stable).')
md.append('')
for count, _, _ in COUNTS:
    md.append('### %d rows' % count)
    md.append('')
    md.append('| band | steps | status | sum diff | rounding | estimate | edge, request length alone | edge, added by load | idle |')
    md.append('|---|---|---|---|---|---|---|---|---|')
    for name, _, _ in BANDS:
        e = agg[count][name]
        for stat, key in (('wait read', 'measured'), ('edge from the residual', 'residual')):
            a = e[key]
            if a and a['n']:
                md.append('| %s | %d | %s | %s | %s | %s | %s | %s | %s |' % (name, a['n'], stat, f(a['diff_pt']), f(a['d_rounding']), f(a['a_estimate']), f(a['edge_unloaded']), f(a['edge_extra']), f(a['idle'])))
                if abs(a['diff_pt']) >= 3.0:
                    sh = lambda v: 'NA' if v is None else '%d%%' % round(v / a['diff_pt'] * 100)
                    md.append('| | | share of diff | 100%% | %s | %s | %s | %s | %s |' % (sh(a['d_rounding']), sh(a['a_estimate']), sh(a['edge_unloaded']), sh(a['edge_extra']), sh(a['idle'])))
        n = e['not_allocated']
        if n['n']:
            md.append('| %s | %d | not split (wait cut at the end of the step) | %s | %s | (estimate + edge together) %s | | | %s |' % (name, n['n'], f(n['diff_pt']), f(n['d_rounding']), f(n['rest_pt']), f(n['idle'])))
    md.append('')
md.append('## The 80% line: the two steps that bound the measured crossing, by scenario')
md.append('')
md.append('| rows | scenario | rps | measured % | predicted % | diff | status | rounding | estimate | edge, length alone | edge, added by load | idle | one request over window (upper bound, weighted) |')
md.append('|---|---|---|---|---|---|---|---|---|---|---|---|---|')
for r in near:
    md.append('| %d | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |' % (r['count'], r['run'], r['rps'], g(r['meas_pt']), g(r['pred_pt']), f(r['diff_pt']), r['status'], f(r['d_rounding']), f(r['a_estimate']),
                                                                                  f(r['edge_unloaded']), f(r['edge_extra']), f(r['idle']), g(r['weighted_ratio_upper'], 3)))
md.append('')
md.append('## Limit rps recomputed from the tables (a check against the score of the records)')
md.append('')
md.append('Measured limit: the straight line between the two steps that bound 80% of the measured utilisation. Predicted limit: 80% over the predicted utilisation per rps (the prediction is proportional to rps).')
md.append('')
md.append('| rows | scenario | measured limit rps | predicted limit rps | error % | window of the upper step (s) | ZIP request alone (s) | alone over window | longest request of the upper step over window | measured limit with the sent count scaled to the decided rps | error % |')
md.append('|---|---|---|---|---|---|---|---|---|---|---|')
for r in limit_rows:
    md.append('| %d | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |' % (r['count'], r['run'], g(r['measured_limit_rps'], 4), g(r['predicted_limit_rps'], 4), f(r['error_pct'], 1), g(r.get('window_upper_s'), 0), g(r.get('T0_s'), 1),
                                                                        g(r.get('T0_over_window'), 3), g(r.get('upper_longest_over_window'), 3), g(r.get('measured_limit_normalised_rps'), 4), f(r.get('error_normalised_pct'), 1)))
md.append('')
md.append('By count (the scenarios that have a measured limit): mean error of the predicted limit, mean of the ZIP request\'s time alone over the window of the step above 80%, and the same with the longest request of that step.')
md.append('')
md.append('| rows | scenarios | mean error % | mean alone over window | mean longest over window | mean error % with the sent count scaled |')
md.append('|---|---|---|---|---|---|')
for count, _, _ in COUNTS:
    v = [r for r in limit_rows if r['count'] == count and r['measured_limit_rps'] is not None]
    if v:
        md.append('| %d | %d | %s | %s | %s | %s |' % (count, len(v), f(statistics.mean(r['error_pct'] for r in v), 1), g(statistics.mean(r['T0_over_window'] for r in v), 3),
                                                      g(statistics.mean(r['upper_longest_over_window'] for r in v), 3), f(statistics.mean(r['error_normalised_pct'] for r in v), 1)))
md.append('')
md.append('## Model of the edge, checked where the records give it directly (low load, wait read)')
md.append('')
md.append('| rows | steps | sum of spill read from the records (CPU s) | sum of spill from the model (CPU s) | edge model minus edge record, per step: min / median / max (CPU s) |')
md.append('|---|---|---|---|---|')
for count, v in val_by_count.items():
    d = v['edge_model_minus_record_cpu_s']
    md.append('| %d | %d | %s | %s | %s / %s / %s |' % (count, v['n'], g(v['sum_record_spill']), g(v['sum_model_spill']), f(d['min']), f(d['median']), f(d['max'])))
md.append('')
md.append('## One request over the window against the size of the miss')
md.append('')
md.append('| rows | steps | Spearman: diff vs weighted upper bound of a request over window (all steps) | same, steps at or above 60% measured | diff vs longest request of the step over window (all steps) | same, 60% up | steps (60% up) |')
md.append('|---|---|---|---|---|---|---|')
for count, _, _ in COUNTS:
    r = relation[count]
    md.append('| %d | %d | %s | %s | %s | %s | %d |' % (count, r['n'], f(r['spearman_all']), f(r['spearman_60_up']), f(r['spearman_longest_all']), f(r['spearman_longest_60_up']), r['n_60_up']))
md.append('')
md.append('## Allocation over all steps of a band that could be split (wait read and edge from the residual together)')
md.append('')
md.append('Shares are of the sum of `diff`; printed only where that sum is 3 points or more in size. The 300,000-row steps whose wait was cut are not in this table (see above).')
md.append('')
md.append('| rows | band | steps | sum diff | rounding | estimate | edge, length alone | edge, added by load | idle |')
md.append('|---|---|---|---|---|---|---|---|---|')
for count, _, _ in COUNTS:
    for name, lo, hi in BANDS:
        rows = [s for s in steps if s['count'] == count and lo <= s['meas_pt'] < hi and s['status'] in ('measured', 'residual')]
        if not rows:
            continue
        a = aggregate(rows)
        md.append('| %d | %s | %d | %s | %s | %s | %s | %s | %s |' % (count, name, a['n'], f(a['diff_pt']), f(a['d_rounding']), f(a['a_estimate']), f(a['edge_unloaded']), f(a['edge_extra']), f(a['idle'])))
        if abs(a['diff_pt']) >= 3.0:
            sh = lambda v: 'NA' if v is None else '%d%%' % round(v / a['diff_pt'] * 100)
            md.append('| | | share | 100%% | %s | %s | %s | %s | %s |' % (sh(a['d_rounding']), sh(a['a_estimate']), sh(a['edge_unloaded']), sh(a['edge_extra']), sh(a['idle'])))
md.append('')
md.append('## The owner\'s four points, as numbers')
md.append('')
md.append('Point 1 (below about 60% measured, the difference is small and of both signs, so the estimate of one request is right there):')
md.append('')
md.append('| rows | steps below 60% | diff min | diff max | steps above 0 | steps below 0 | mean diff | diff + idle: min | diff + idle: max | kappa (measured work over predicted work) min / median / max |')
md.append('|---|---|---|---|---|---|---|---|---|---|')
for count, _, _ in COUNTS:
    p1 = points[count]['low']
    md.append('| %d | %d | %s | %s | %d | %d | %s | %s | %s | %s / %s / %s |' % (count, p1['n_low'], f(p1['diff_min']), f(p1['diff_max']), p1['n_pos'], p1['n_neg'], f(p1['diff_mean']), f(p1['diff_plus_idle_min']),
                                                                                f(p1['diff_plus_idle_max']), g(p1['kappa_min'], 3), g(p1['kappa_median'], 3), g(p1['kappa_max'], 3)))
md.append('')
md.append('Point 2 (the difference opens from about 70%% measured, and the measured utilisation bends below the straight line of the prediction): measured utilisation per rps (the idle rate taken off) against its median at the steps below %d%%, by scenario; the first step above that range where it is below 0.95 of that.' % LOW_STEP_BELOW)
md.append('')
md.append('| rows | scenario | measured % per rps, low steps | predicted % per rps, low steps | first step below 0.95: rps | measured % there | predicted % there | ratio there |')
md.append('|---|---|---|---|---|---|---|---|')
for count, _, _ in COUNTS:
    for b in points[count]['high']['bend']:
        fb = b['first_below_95pct']
        md.append('| %d | %s | %s | %s | %s | %s | %s | %s |' % (count, b['run'], g(b['ref_meas_per_rps'], 1), g(b['ref_pred_per_rps'], 1), 'none' if not fb else fb['rps'], 'NA' if not fb else g(fb['meas_pt'], 1),
                                                           'NA' if not fb else g(fb['pred_pt'], 1), 'NA' if not fb else g(fb['ratio'], 3)))
md.append('')
md.append('Steps at or above 70% measured (scenario, rps, measured %, diff):')
md.append('')
for count, _, _ in COUNTS:
    md.append('- %d rows: %s' % (count, '; '.join('%s %s: %.1f%%, %+.1f' % (a, b, c, d) for a, b, c, d in points[count]['high']['diff_hi'])))
md.append('')
md.append('## Residual steps: how far the edge piece moves with kappa, and the check against the upper bound of the records')
md.append('')
md.append('The last step of a scenario has no next step to read the end of the wait from, so its factor kappa is taken as the median of the same scenario\'s other steps (`kappa_used`), with the range of those steps. `edge, added by load` is what is left after the pieces that are known; the range column moves kappa over the scenario\'s range. Where the records give an upper bound of the CPU after the window, the cap is the edge piece if the whole of that bound were spill.')
md.append('')
md.append('| rows | scenario | rps | measured % | edge, added by load (kappa median) | range over kappa | cap from the upper bound | residual spill (CPU s) | upper bound (CPU s) |')
md.append('|---|---|---|---|---|---|---|---|---|')
for r in out['residual_checks']:
    s0 = [x for x in steps if x['count'] == r['count'] and x['run'] == r['run'] and x['rps'] == r['rps']][0]
    md.append('| %d | %s | %s | %s | %s | %s to %s | %s | %s | %s |' % (r['count'], r['run'], r['rps'], g(s0['meas_pt'], 1), f(r['edge_extra'], 1), f(r['sens'][0]['edge_extra'], 1), f(r['sens'][1]['edge_extra'], 1),
                                                                      f(r['edge_extra_cap_by_bound'], 1), g(r['spill_resid_cpu_s'], 1), g(r['spill_upper_s'], 1)))
md.append('')
md.append('## What one ZIP request draws from the database, and the time it takes alone')
md.append('')
md.append('The prediction\'s work of one ZIP request divided by the time that request takes in the steps of low load (the longest request of those steps, median over the steps below %d%% of the scenario) gives the share of the database it draws while it runs.' % LOW_STEP_BELOW)
md.append('')
md.append('| rows | ZIP work per request (CPU s) | time alone (s): min / median / max over the 5 scenarios | cores drawn: min / median / max |')
md.append('|---|---|---|---|')
for count, _, _ in COUNTS:
    ts = sorted(set(round(s['T0'], 1) for s in steps if s['count'] == count and s['T0']))
    zw = sorted(set(round(s['zip_w'], 2) for s in steps if s['count'] == count))
    rc = rate_by_count[count]
    md.append('| %d | %s | %s / %s / %s | %s / %s / %s |' % (count, ', '.join(map(str, zw)), g(ts[0], 1), g(statistics.median(ts), 1), g(ts[-1], 1), g(rc['min'], 3), g(rc['median'], 3), g(rc['max'], 3)))
md.append('')
md.append('## Trials on records already seen (NOT results and NOT tests)')
md.append('')
md.append('Every count here was seen before the form of each trial was written. They are arithmetic on the records and show the size of what each proposal would touch; none is a prediction of an unseen count. The tables of the three counts and the prediction are not changed.')
md.append('')
md.append('### Trial A: a ZIP request draws %.3f cores while it runs, and the database gives at most 2 cores to all the requests together' % R_FIXED)
md.append('')
md.append('The rate %.3f is the median over the 5 scenarios at 90,000 rows only; the work of a request is the prediction\'s, the arrival times are the plan\'s, the idle rate is the one above. No kappa and no fit to a step. The window is read as the tool reads it.' % R_FIXED)
md.append('')
md.append('| rows | band of measured % | steps | mean absolute error of the prediction (pt) | mean absolute error of trial A (pt) | mean error of the prediction | mean error of trial A |')
md.append('|---|---|---|---|---|---|---|---|')
for (count, name), v in trialA_stats.items():
    md.append('| %d | %s | %d | %s | %s | %s | %s |' % (count, name, v['n'], g(v['pred_mean_abs']), g(v['trialA_mean_abs']), f(v['pred_mean']), f(v['trialA_mean'])))
md.append('')
md.append('Limit rps (the crossing of 80%) of the measurement, of the prediction, and of the series of trial A and of trial B:')
md.append('')
md.append('| rows | scenario | measured | prediction | error % | trial A (the series of trial A against the measured limit) | error % | trial B (extension of the highest step whose wait is read; the measurement moved, not the prediction) | the prediction against it, % | highest step used: rps | measured % there |')
md.append('|---|---|---|---|---|---|---|---|---|---|---|')
for t in trials:
    md.append('| %d | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |' % (t['count'], t['run'], g(t['measured_limit'], 4), g(t['predicted_limit'], 4), f(t['pred_err_pct'], 1), g(t['trialA_limit'], 4), f(t['trialA_err_pct'], 1),
                                                                    g(t['trialB_limit'], 4), f(t['pred_vs_trialB_pct'], 1), 'NA' if t['trialB_from_rps'] is None else t['trialB_from_rps'], g(t['trialB_from_meas_pt'], 1)))
md.append('')
md.append('Trial B counts, for the steps whose wait was read, the work of the requests sent in the window in full (the window\'s work, less the carry-in, plus the spill) with the idle kept. It changes the measured side, so the prediction is set against the limit that this count gives (the column "the prediction against it"), not against the limit of the record. Its limit is the highest such step extended in proportion to rps up to 80%, because the step above 80% has no read wait; where that step is far below 80% (56% to 62% at several scenarios; 13.5% and 14.7% at 300,000 rows, so those are not read) the extension is long and rests on the measured work per rps staying flat.')
md.append('')
md.append('### Trial C: examples of a rule "the load was not got through", read from the records of the steps')
md.append('')
md.append('The stretch is the longest request of the step over the longest request of the same scenario\'s steps below %d%% measured. The thresholds 1.10, 1.25 and 1.50 are examples to show how much the rps depends on them, not a choice. The table gives the first rps of the scenario at which the stretch reaches each (with the measured utilisation there).' % LOW_STEP_BELOW)
md.append('')
md.append('| rows | scenario | measured limit rps | first rps at stretch 1.10 | at 1.25 | at 1.50 |')
md.append('|---|---|---|---|---|---|')
for t, c in zip(trials, trialC):
    cell = lambda k: 'none' if c['stretch_first_rps_at_%s' % k] is None else '%s (%s%%)' % (c['stretch_first_rps_at_%s' % k], g(c['stretch_first_meas_at_%s' % k], 0))
    md.append('| %d | %s | %s | %s | %s | %s |' % (t['count'], t['run'], g(t['measured_limit'], 4), cell('1_10'), cell('1_25'), cell('1_50')))
md.append('')
md.append('For comparison, a rule on the share of the window\'s requests that finished after it (5%, 10%, 20%) is met at low load already at 170,000 and 300,000 rows (a ZIP request of 122 s or more lasts past the end of the window whatever the load): at 170,000 rows `s-1` reaches 5% at 0.1 rps, 43% measured, against a measured limit of 0.2195 rps.')
md.append('')
md.append('## Work already running when a step started (an earlier step\'s requests)')
md.append('')
md.append('The database CPU in the discarded minute (idle taken off) less what the step\'s own requests of that minute do in it (the model of the edge, with kappa). At 90,000 and 170,000 rows the wait was never cut and the database was idle at the start of every step, so the value on those steps is a check of the model: it should be near zero.')
md.append('')
md.append('| rows | steps starting idle | min / median / max (CPU s) | steps starting with work running | min / median / max (CPU s) |')
md.append('|---|---|---|---|---|')
for count, _, _ in COUNTS:
    v = incoming_stats[count]
    md.append('| %d | %d | %s / %s / %s | %d | %s / %s / %s |' % (count, v['clean_n'], f(v['clean_min'], 1), f(v['clean_median'], 1), f(v['clean_max'], 1), v['other_n'], f(v['other_min'], 1), f(v['other_median'], 1), f(v['other_max'], 1)))
md.append('')
md.append('The 300,000-row steps that started with work running (the load tool had cut requests at the end of the step before, which went on in the database):')
md.append('')
md.append('| scenario | rps | measured % | rate at the start (cores) | incoming work in the discarded minute (CPU s) | over the window\'s CPU time |')
md.append('|---|---|---|---|---|---|')
for s in steps:
    if s['count'] == 300000 and not s['clean_start']:
        md.append('| %s | %s | %s | %s | %s | %s |' % (s['run'], s['rps'], g(s['meas_pt'], 1), g(s['pre_start_rate'], 3), f(s['incoming_cpu_s'], 1), g(s['incoming_over_window_cpu'], 3)))
md.append('')
md.append('## Per step')
md.append('')
md.append('See `allocation_by_step.csv`.')
md.append('')
with open(os.path.join(HERE, 'allocation-tables.md'), 'w', encoding='utf-8') as fh:
    fh.write('\n'.join(md))

bad = [n for n, ok in CHECKS if not ok]
print('steps', len(steps), 'checks', len(CHECKS), 'failed', len(bad))
for n in bad[:20]:
    print('FAIL', n)
sys.exit(1 if bad else 0)
