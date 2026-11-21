"""Tables of the 300,000-row ladder of B, step by step, for the reading of the miss of the limit rps.

A reading of records that already exist. It runs no measurement, calls nothing in the lab, and changes no prediction, estimate,
calibration, formula, rule or score. It adds no number of its own: every input is read from a committed record.

    python3 -I build_table.py       (run from any directory; writes step_table.csv, route_table.csv and tail_table.csv beside itself)

Inputs (paths from the root of the repository):
  poc/lab/predictions/ladder-prediction-3.json                      committed prediction: the point utilisation of each stage, the database
                                                                    time of one request of each route (point and upper side), the bound on
                                                                    the PHP CPU time of one request
  docs/research/lab-data/estimate-300k/300k/estimate.json           the row estimate the prediction used (statements and rows per request;
                                                                    only checked against the prediction's database time)
  docs/research/lab-data/ladder-300k/s-N-300000/rung-NN-<rps>/      per step: plan.json, run.json, summary.json, k6-output.txt, cpu.txt

Definitions:
  sent_requests          requests sent inside the kept window = planned_window_requests of plan.json. The tool of this round did not count
                         the requests sent (it counted the requests that had finished); the plan is exact because the load tool's dropped
                         iterations are 0 in every step (run.json) and its arrival times are fixed by the plan (checked below: the arrival
                         schedule rebuilt from the plan gives the same count in the window as the plan states).
  pred_work_per_req      the prediction's database time of one request, point side (c_q x statements + c_row x rows, from the estimate;
                         checked below against the estimate and the calibration of the same prediction file)
  pred_work_cpu_s        sum over routes of sent_requests x pred_work_per_req
  pred_util_pct          the prediction's point utilisation of the stage with the same total rps (a rate x time, from the rps; this differs
                         a little from pred_work_cpu_s / (cores x window) because the planned counts are whole numbers)
  meas_util_pct          db_utilization_percent of the step record = the database container's CPU time over the window / (2 cores x window)
  after_window_requests  requests of the step still running when the scenario (and the window) ended, read from the load tool's
                         progress lines (every second, per route, the number of VUs busy). It includes a request sent in the discarded
                         first minute that is still running, which the tool cannot tell from one sent in the window.
  max_end_of_step_delay  the last second at which any request of the step was still running, minus the end of the scenario. The load
                         tool of this round waited at most 30 s for running requests (k6's own default; the record has no end_of_step
                         field), then cut them: a delay of ">=30" means the wait ended before the request did.
  spill_work_cpu_s       the database container's CPU time read from its counter between the window's end and 30 s later (the wait),
                         linear between the two samples around each end, **only for the steps in which the tool cut no request**
                         (all of that work is then inside the wait; it is not net of idle, about 0.02 core = 0.6 s in 30 s). NA for the steps in
                         which the tool cut running requests: their work goes on after +30 s and into the next step; the counter of the wait
                         (tail30_cpu_s in tail_table.csv) is a lower bound there. spill_missing says what is missing.
  req_time_s             upper bound of the PHP CPU time of one request (the prediction's php_cpu_bound_s, as in lab-backtest-5.md), seconds
  weight                 the route's share of the stage's rps (plan.json share)
"""

import csv
import glob
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.normpath(os.path.join(HERE, '..', '..', '..', '..', '..', '..'))
LAB = os.path.join(ROOT, 'docs', 'research', 'lab-data')
LADDER = os.path.join(LAB, 'ladder-300k')
COUNT = 300000
CORES = 2.0
ZIP = 'GET /books/{bookSlug}/export/zip'
BOOK = 'GET /books/{slug}'
GRACE = 30.0  # k6's default wait for running requests at the end of a scenario (the tool of this round did not set it)


def load(path):
    with open(path, encoding='utf-8') as fh:
        return json.load(fh)


def fmt(x, nd=6):
    if x is None:
        return 'NA'
    if isinstance(x, str):
        return x
    if isinstance(x, int):
        return str(x)
    return ('%.*g' % (nd, x))


pred = load(os.path.join(ROOT, 'poc', 'lab', 'predictions', 'ladder-prediction-3.json'))
est = load(os.path.join(LAB, 'estimate-300k', '300k', 'estimate.json'))
CQ = float(pred['calibration']['coefficients']['c_q'])
CR = float(pred['calibration']['coefficients']['c_row'])
assert float(pred['resources']['cores']) == CORES
scen = {s['id']: s for s in pred['scenarios']}

# ---- per-route values of the prediction (one request) ---------------------------------------------------------------------
route_pred = {}
for r in scen['s-1']['app_limit_risk']['routes']:
    route_pred[r['route']] = {
        'work_point': float(r['db_time_s']['point']),
        'work_upper': float(r['db_time_s']['upper']),
        'php_bound': float(r['php_cpu_bound_s']),
    }
est_routes = {r['route']: r for r in est['routes']}
max_k_dev = 0.0
for name, v in route_pred.items():
    e = est_routes[name]
    k_est = CQ * float(e['queries']) + CR * float(e['rows_examined'])
    max_k_dev = max(max_k_dev, abs(k_est - v['work_point']))
assert max_k_dev < 1e-9, max_k_dev  # the prediction's one-request time is c_q x statements + c_row x rows of the estimate
for s in pred['scenarios']:  # the same one-request values in every scenario
    for r in s['app_limit_risk']['routes']:
        assert abs(float(r['db_time_s']['point']) - route_pred[r['route']]['work_point']) < 1e-12

# ---- the load tool's progress lines ----------------------------------------------------------------------------------------
HDR = re.compile(r'^running \((?:(\d+)m)?([\d.]+)s\), (\d+)/(\d+) VUs, (\d+) complete and (\d+) interrupted')
RTE = re.compile(r'^(r\d+)\s+(?:[^\s\[]\s+)?\[\s*\d+% \]\s+(?:(\d+)/(\d+) VUs)?')


def parse_k6(path):
    """-> ({route index: [(seconds since the scenario's start, VUs busy)]}, (seconds, complete, interrupted) of the last header)"""
    t = None
    series = {}
    last = None
    with open(path, encoding='utf-8', errors='replace') as fh:
        for line in fh:
            m = HDR.match(line)
            if m:
                t = int(m.group(1) or 0) * 60 + float(m.group(2))
                last = (t, int(m.group(5)), int(m.group(6)))
                continue
            m = RTE.match(line)
            if m and t is not None:
                series.setdefault(m.group(1), []).append((t, int(m.group(2)) if m.group(2) is not None else 0))
    return series, last


def arrivals(route, warmup, duration):
    """arrival times of the stream of one route (constant arrival rate: the first at its start, then evenly spaced)"""
    unit = float(route['time_unit'].rstrip('s'))
    step = unit / float(route['rate'])
    t0 = float(route.get('start_offset_sec') or 0)
    out = []
    k = 0
    while t0 + k * step < duration - 1e-9:
        out.append(t0 + k * step)
        k += 1
    return out


# ---- the database container's CPU counter ----------------------------------------------------------------------------------
def read_cpu(path):
    pts = []
    with open(path, encoding='utf-8') as fh:
        for line in fh:
            p = line.split()
            if len(p) == 2:
                pts.append((float(p[0]), float(p[1]) / 1e6))  # seconds, CPU seconds
    return pts


def at(pts, t):
    """the counter at time t, linear between the two samples around it (None outside the sampled span)"""
    if t < pts[0][0] or t > pts[-1][0]:
        return None
    for (t0, u0), (t1, u1) in zip(pts, pts[1:]):
        if t0 <= t <= t1:
            return u0 + (u1 - u0) * (t - t0) / (t1 - t0)
    return None


# ---- steps ------------------------------------------------------------------------------------------------------------------
step_rows = []
route_rows = []
tail_rows = []
checks = {'interp_dev_max_cpu_s': 0.0, 'sent_vs_schedule_mismatch': 0, 'planned_vs_run_mismatch': 0, 'dropped_nonzero': 0,
          'util_from_sent_dev_max_pt': 0.0}

step_dirs = []
for sc in range(1, 6):
    for rd in sorted(glob.glob(os.path.join(LADDER, 's-%d-%d' % (sc, COUNT), 'rung-*'))):
        if os.path.exists(os.path.join(rd, 'record.json')):  # s-5 step 9 was stopped by the memory guard and has no record
            step_dirs.append((sc, rd))

prev_tail_rate = {}
for sc, rd in step_dirs:
    run_id = 's-%d' % sc
    rec = load(os.path.join(rd, 'record.json'))
    plan = load(os.path.join(rd, 'plan.json'))
    run = load(os.path.join(rd, 'run.json'))
    summ = load(os.path.join(rd, 'summary.json'))
    rps = float(rec['decided_rps'])
    window = float(plan['window_sec'])
    warm = float(plan['warmup_sec'])
    dur = float(plan['duration_sec'])
    assert dur == warm + window and float(run['window_sec']) == window
    if int(rec['k6_dropped_iterations']) != 0:
        checks['dropped_nonzero'] += 1
    stage = [s for s in scen[run_id]['stages'] if abs(float(s['rps_total']) - rps) < 1e-12]
    assert len(stage) == 1
    pred_util = float(stage[0]['utilization']['point']) * 100.0
    meas_util = float(rec['db_utilization_percent'])

    # requests sent per route, and the check of the plan against the rebuilt schedule and against the run record
    run_routes = {x['route']: x for x in run['per_route']}
    sent = {}
    warm_sent = {}
    for pr in plan['routes']:
        arr = arrivals(pr, warm, dur)
        n_win = sum(1 for a in arr if a >= warm)
        if n_win != int(pr['planned_window_requests']):
            checks['sent_vs_schedule_mismatch'] += 1
        if int(run_routes[pr['route']]['planned_requests']) != int(pr['planned_window_requests']):
            checks['planned_vs_run_mismatch'] += 1
        sent[pr['route']] = int(pr['planned_window_requests'])
        warm_sent[pr['route']] = len(arr) - n_win
    total_sent = sum(sent.values())
    work = {r: sent[r] * route_pred[r]['work_point'] for r in sent}
    pred_work = sum(work.values())
    checks['util_from_sent_dev_max_pt'] = max(checks['util_from_sent_dev_max_pt'],
                                               abs(pred_work / (CORES * window) * 100.0 - pred_util))

    # in-flight series
    series, last = parse_k6(os.path.join(rd, 'k6-output.txt'))
    names = {'r%d' % i: pr['route'] for i, pr in enumerate(plan['routes'])}
    inflight_end = {}
    max_in = {}
    max_in_win = {}
    first2 = {}
    last_active = {}
    for key, route in names.items():
        s = series.get(key, [])
        if not s:
            continue
        end_pt = min(s, key=lambda p: abs(p[0] - dur))
        inflight_end[route] = end_pt[1] if abs(end_pt[0] - dur) <= 1.5 else None
        max_in[route] = max(v for _, v in s)
        win = [(t, v) for t, v in s if warm <= t <= dur]
        max_in_win[route] = max((v for _, v in win), default=0)
        f2 = [t for t, v in win if v >= 2]
        first2[route] = min(f2) if f2 else None
        act = [t for t, v in s if v > 0 and t >= dur - 1.0]
        last_active[route] = max(act) if act else None
    have_series = bool(inflight_end) and all(v is not None for v in inflight_end.values())
    after_req = sum(inflight_end.values()) if have_series else None
    interrupted = last[2] if last else None
    cut = bool(interrupted) and last is not None and last[0] >= dur + GRACE - 1.0
    if have_series:
        la = [t for t in last_active.values() if t is not None]
        if cut:
            delay = '>=%d' % int(GRACE)
        elif la:
            delay = fmt(max(la) - dur, 4)
        else:
            delay = fmt(0.0, 1)
    else:
        delay = 'NA'

    # the CPU counter
    pts = read_cpu(os.path.join(rd, 'cpu.txt'))
    a, b = float(run['window_epoch']['from']), float(run['window_epoch']['to'])
    ua, ub = at(pts, a), at(pts, b)
    win_cpu = ub - ua
    checks['interp_dev_max_cpu_s'] = max(checks['interp_dev_max_cpu_s'], abs(win_cpu - float(run['db_utilization']['delta_usage_usec']) / 1e6))
    u30 = at(pts, b + GRACE)
    spill = (u30 - ub) if u30 is not None else None
    t_last, u_last = pts[-1]
    to_end = u_last - ub
    pre_rate = (pts[1][1] - pts[0][1]) / (pts[1][0] - pts[0][0])
    last_rate = (pts[-1][1] - pts[-2][1]) / (pts[-1][0] - pts[-2][0])
    ca = at(pts, b - 60.0)
    rate_last60 = (ub - ca) / 60.0
    u50 = at(pts, b + 50.0)
    post_wait_rate = (u50 - u30) / 20.0 if (u30 is not None and u50 is not None) else None
    rate_win = win_cpu / window

    # queue sign from the busy counts
    if have_series:
        stack = [r for r in names.values() if (inflight_end.get(r) or 0) >= 2]
        ever = [r for r in names.values() if max_in_win.get(r, 0) >= 2]
        queue_sign = 'yes' if stack else 'no'
        parts = []
        for r in names.values():
            if max_in.get(r, 0) >= 2 or (inflight_end.get(r) or 0) >= 1:
                f2 = first2.get(r)
                parts.append('%s: in flight at the end %s, highest count in the window %s%s' % (
                    r.split(' ', 1)[1], inflight_end.get(r), max_in_win.get(r, 0),
                    ('' if f2 is None else ', two or more first at %.0f s of the scenario' % f2)))
        evidence = '; '.join(parts) if parts else 'no request in flight at the end of any route'
        evidence += '; db cpu rate last 60 s of window %.2f cores, whole window %.2f' % (rate_last60, rate_win)
        evidence += '; longest completed request of the step %.1f s (route not recorded)' % (float(summ['duration_ms']['max']) / 1000.0)
    else:
        queue_sign, evidence = 'NA', 'no progress lines'

    # the weighted ratio and the per-route rows
    ratio_w = 0.0
    for pr in plan['routes']:
        r = pr['route']
        rt = route_pred[r]['php_bound']
        ratio = rt / window
        weight = float(pr['share'])
        ratio_w += weight * ratio
        route_rows.append({
            'count': COUNT, 'run': run_id, 'step_rps': fmt(rps), 'route': r, 'sent_requests': sent[r],
            'pred_work_per_req_cpu_s': fmt(route_pred[r]['work_point']),
            'pred_work_cpu_s': fmt(work[r]),
            'req_time_s': fmt(rt), 'req_time_source': 'upper_bound',
            'req_over_window': fmt(ratio), 'weight': fmt(weight),
        })

    if interrupted:
        # the tool cut running requests at +30 s: their work goes on after the wait, into the next step, and cannot be separated from it
        spill_value = None
        spill_missing = ('%d request(s) were cut by the tool at +30 s and their work went on (tail_table.csv: the rate does not fall to idle at +30 s), so the work after the window of requests sent in the window is not '
                         'complete in the 30 s wait and is not separable from the work of requests sent in the discarded minute or left by '
                         'the step before; the CPU time of each request is not in the record. What can be read is the counter in the wait '
                         '(tail30_cpu_s) and to the last sample (to_last_sample_cpu_s), in tail_table.csv, as a lower bound' % interrupted)
    else:
        spill_value = spill
        spill_missing = ('value is the database CPU of the 30 s wait, all requests together (no request was cut); missing: the CPU time of each '
                         'request, so the work of requests sent in the window is not split from requests sent in the discarded minute or left '
                         'by the step before')
    step_rows.append({
        'count': COUNT, 'run': run_id, 'step_rps': fmt(rps), 'window_s': fmt(window, 5),
        'pred_util_pct': fmt(pred_util, 6), 'meas_util_pct': fmt(meas_util, 6), 'diff_pt': fmt(pred_util - meas_util, 6),
        'sent_requests': total_sent, 'pred_work_cpu_s': fmt(pred_work, 6),
        'after_window_requests': fmt(after_req) if after_req is not None else 'NA',
        'max_end_of_step_delay_s': delay,
        'spill_work_cpu_s': fmt(spill_value, 5), 'spill_missing': spill_missing,
        'weighted_req_over_window': fmt(ratio_w, 6), 'queue_sign': queue_sign, 'queue_evidence': evidence,
    })
    tail_rows.append({
        'count': COUNT, 'run': run_id, 'step_rps': fmt(rps), 'window_s': fmt(window, 5),
        'window_cpu_s_from_counter': fmt(win_cpu, 6), 'window_cpu_s_in_record': fmt(float(run['db_utilization']['delta_usage_usec']) / 1e6, 6),
        'pre_start_rate_cores': fmt(pre_rate, 3),
        'tail30_cpu_s': fmt(spill, 5), 'tail30_rate_cores': fmt(spill / GRACE, 3), 'post_wait_rate_cores': fmt(post_wait_rate, 3),
        'last_sample_after_window_end_s': fmt(t_last - b, 4), 'to_last_sample_cpu_s': fmt(to_end, 5),
        'last_interval_rate_cores': fmt(last_rate, 3),
        'window_rate_last60_cores': fmt(rate_last60, 3), 'window_rate_cores': fmt(rate_win, 3),
        'interrupted_at_cut': fmt(interrupted) if interrupted is not None else 'NA',
        'ended_at': rec['ended_at'], 'started_at': rec['started_at'],
    })

fields_step = ['count', 'run', 'step_rps', 'window_s', 'pred_util_pct', 'meas_util_pct', 'diff_pt', 'sent_requests', 'pred_work_cpu_s',
               'after_window_requests', 'max_end_of_step_delay_s', 'spill_work_cpu_s', 'spill_missing', 'weighted_req_over_window',
               'queue_sign', 'queue_evidence']
fields_route = ['count', 'run', 'step_rps', 'route', 'sent_requests', 'pred_work_per_req_cpu_s', 'pred_work_cpu_s', 'req_time_s',
                'req_time_source', 'req_over_window', 'weight']
fields_tail = ['count', 'run', 'step_rps', 'window_s', 'window_cpu_s_from_counter', 'window_cpu_s_in_record', 'pre_start_rate_cores',
               'next_step_pre_start_rate_cores', 'tail30_cpu_s', 'tail30_rate_cores', 'post_wait_rate_cores', 'last_sample_after_window_end_s', 'to_last_sample_cpu_s', 'last_interval_rate_cores',
               'window_rate_last60_cores', 'window_rate_cores', 'interrupted_at_cut', 'started_at', 'ended_at']


# the rate at which the database was already working just before the NEXT step of the same scenario began (work of cut requests of this step)
for i, row in enumerate(tail_rows):
    nxt = tail_rows[i + 1] if i + 1 < len(tail_rows) and tail_rows[i + 1]['run'] == row['run'] else None
    row['next_step_pre_start_rate_cores'] = nxt['pre_start_rate_cores'] if nxt else 'NA'


def write(name, fields, rows):
    with open(os.path.join(HERE, name), 'w', encoding='utf-8', newline='') as fh:
        w = csv.DictWriter(fh, fieldnames=fields, lineterminator='\n')
        w.writeheader()
        for row in rows:
            w.writerow({k: row[k] for k in fields})


write('step_table.csv', fields_step, step_rows)
write('route_table.csv', fields_route, route_rows)
write('tail_table.csv', fields_tail, tail_rows)

print('steps %d, route rows %d' % (len(step_rows), len(route_rows)))
print('self-checks: max |c_q q + c_row rows - db_time_s point| over routes = %.3g' % max_k_dev)
for k, v in checks.items():
    print('  %s = %s' % (k, v))
