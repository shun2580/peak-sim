#!/usr/bin/env python3
"""Reads the records of the ladder measurements and the lab's nginx access log, and writes the tables of this directory.

  python3 -I docs/research/lab-data/diagnosis/no-response/analyze.py [--out-dir <lab out dir>]

Inputs (read only, nothing is sent to the lab):
  - docs/research/lab-data/ladder/<scenario>/rung-*/{plan,summary,run}.json and cpu.txt   (in the repository)
  - <lab out dir>/web/access.log                  the web server's log: completion time, status, request seconds, URI
  - <lab out dir>/targets-<scale>.json            URI -> route
  - <lab out dir>/ladder/<scenario>/rung-*/k6-output.txt (older records: k6.log) the load tool's own log (its last progress line: interrupted iterations)
The lab out dir defaults to $PEAK_LAB_OUT_DIR, else ~/.cache/peak-lab/out (it is outside the repository; the log files are not committed).

Writes next to this file: step-summary.csv, status0-by-route.csv, status0-requests.csv, limit-sensitivity.csv, db-util-around-window-end.csv, status500-seconds.csv.
"""
import collections, csv, glob, json, math, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..', '..', '..'))
LADDER = os.path.join(REPO, 'docs', 'research', 'lab-data', 'ladder')
OUT = os.environ.get('PEAK_LAB_OUT_DIR') or os.path.expanduser('~/.cache/peak-lab/out')
if '--out-dir' in sys.argv:
    OUT = sys.argv[sys.argv.index('--out-dir') + 1]

TIMEOUT_MIN_SEC = 59.0  # a 499 that lasted this long was the load tool giving up (it measures from slightly earlier than the web server)


def load_samples(path):
    return [tuple(map(float, l.split())) for l in open(path) if l.strip()]


def interp(samples, t):
    for (t0, u0), (t1, u1) in zip(samples, samples[1:]):
        if t0 <= t <= t1:
            return u0 + (u1 - u0) * (t - t0) / (t1 - t0)
    return None


def utilization(samples, a, b):
    ua, ub = interp(samples, a), interp(samples, b)
    return (ub - ua) / ((b - a) * 1e6 * 2)


def last_progress(k6_log):
    m = re.findall(r'running \((\d+)m([\d.]+)s\).*?(\d+) complete and (\d+) interrupted', k6_log)
    return (int(m[-1][0]) * 60 + float(m[-1][1]), int(m[-1][3])) if m else (None, None)


routes_of = {}
for scale in (10000, 30000, 100000):
    t = json.load(open(os.path.join(OUT, 'targets-%d.json' % scale)))
    routes_of[scale] = {v['path']: r for r, v in t['routes'].items()}

access = []
for line in open(os.path.join(OUT, 'web', 'access.log')):
    p = line.split()
    if len(p) >= 6:
        access.append((float(p[0]), int(p[1]), float(p[2]), p[5]))  # completion epoch, status, request seconds, URI

steps, by_route, requests, bins, s500 = [], collections.Counter(), [], [], []
for sdir in sorted(glob.glob(os.path.join(LADDER, 't-*'))):
    scenario = os.path.basename(sdir)
    scale = int(scenario.split('-')[-1])
    for d in sorted(glob.glob(os.path.join(sdir, 'rung-*'))):
        step = int(os.path.basename(d).split('-')[1])
        plan, summ, run = (json.load(open(os.path.join(d, f))) for f in ('plan.json', 'summary.json', 'run.json'))
        start = summ['scenario_start_ms'] / 1000
        wf, wt = run['window_epoch']['from'], run['window_epoch']['to']
        samples = load_samples(os.path.join(d, 'cpu.txt'))
        raw = next((r for r in (os.path.join(OUT, 'ladder', scenario, os.path.basename(d), n) for n in ('k6-output.txt', 'k6.log')) if os.path.exists(r)), '')
        k6_end, interrupted = last_progress(open(raw).read()) if os.path.exists(raw) else (None, None)
        timeouts, cut_at_end, others = 0, 0, 0
        secs500 = []
        for done, status, secs, uri in access:
            if status == 500 and uri in routes_of[scale] and wf <= done - secs < wt:
                secs500.append((routes_of[scale][uri], secs))
            if status != 499 or uri not in routes_of[scale] or not (wf <= done - secs < wt):
                continue
            if secs >= TIMEOUT_MIN_SEC:
                timeouts += 1
                route = routes_of[scale][uri]
                by_route[(scenario, step, route)] += 1
                requests.append((scenario, step, route, round(done - secs - start, 1), round(secs, 3)))
            elif done >= start + plan['duration_sec'] - 1:
                cut_at_end += 1
            else:
                others += 1
        if secs500:
            for route in sorted({r for r, _ in secs500}):
                v = [x for r, x in secs500 if r == route]
                s500.append([scenario, step, route, len(v), round(min(v), 1), round(sum(v) / len(v), 1), round(max(v), 1)])
        achieved = run['rps']['achieved_rps']
        util = run['db_utilization']['utilization']
        row = dict(
            scenario=scenario, step=step, decided_rps=run['rps']['target_rps'], achieved_rps=round(achieved, 4),
            rps_ratio=round(run['rps']['ratio'], 4), requests_in_window=run['requests']['in_window'],
            planned_in_window=sum(r['planned_window_requests'] for r in plan['routes']),
            k6_status0=summ['http_status'].get('0', 0), k6_max_duration_sec=round(summ['duration_ms']['max'] / 1000, 3),
            nginx_499_timeout=timeouts, nginx_499_cut_at_end=cut_at_end, nginx_499_other=others, k6_interrupted_total=interrupted,
            window_sec=run['window_sec'], scenario_sec=plan['duration_sec'], k6_ended_after_start_sec=k6_end,
            db_util_window=round(util, 4), db_util_per_achieved_rps=round(util / achieved, 4),
            db_util_warmup=round(utilization(samples, start, wf), 4),
            db_util_last_minute=round(utilization(samples, wt - 60, wt), 4),
            db_util_after_window=round(utilization(samples, wt, samples[-1][0]), 4),
            db_cpu_sec_in_window=round(run['db_utilization']['delta_usage_usec'] / 1e6, 1),
            db_cpu_sec_after_window=round((interp(samples, samples[-1][0]) - interp(samples, wt)) / 1e6, 1),
            sampled_after_window_sec=round(samples[-1][0] - wt, 1),
        )
        # the smallest number of requests in the window that keeps the rps within 5% of the decided one (the edge counts as valid)
        row['min_requests_for_valid_rps'] = math.ceil((0.95 - 1e-9) * run['rps']['target_rps'] * run['window_sec'])
        row['requests_above_that_minimum'] = run['requests']['in_window'] - row['min_requests_for_valid_rps']
        row['rps_valid'] = run['rps']['valid']
        row['u_raw'] = util
        steps.append(row)
        if summ['http_status'].get('0', 0):
            acc = collections.defaultdict(lambda: [0.0, 0.0])
            for (t0, u0), (t1, u1) in zip(samples, samples[1:]):
                b = int((t1 - start) // 10) * 10
                acc[b][0] += u1 - u0
                acc[b][1] += t1 - t0
            for b in sorted(acc):
                if b >= wt - start - 40:
                    bins.append([scenario, step, b, round(acc[b][0] / (acc[b][1] * 1e6 * 2), 3), 'after window end' if b >= wt - start else 'in window',
                                 'k6 had exited' if k6_end is not None and b >= k6_end else ''])


def write(name, header, rows):
    with open(os.path.join(HERE, name), 'w', newline='') as f:
        w = csv.writer(f, lineterminator='\n')
        w.writerow(header)
        w.writerows(rows)


# The limit rps as the score reads it: a straight line between the first valid step at 80% or more and the valid step before it, in the
# decided rps. Beside it, the same line if the utilization of the upper step had kept the utilization per achieved rps of the step before it
# (an illustration of how far a saturated step cuts the line short; it is not a score and changes no record).
sens = []
for scenario in sorted({r['scenario'] for r in steps}):
    valid = [r for r in steps if r['scenario'] == scenario and r['rps_valid']]
    j = next((k for k, r in enumerate(valid) if r['u_raw'] >= 0.8), None)
    if j is None or j == 0:
        continue
    lo, hi = valid[j - 1], valid[j]
    line = lambda u_hi: lo['decided_rps'] + (hi['decided_rps'] - lo['decided_rps']) * (0.8 - lo['u_raw']) / (u_hi - lo['u_raw'])
    as_measured = line(hi['u_raw'])
    straight = lo['u_raw'] / lo['achieved_rps'] * hi['achieved_rps']
    sens.append([scenario, lo['step'], hi['step'], round(hi['u_raw'], 4), round(as_measured, 4), round(straight, 4), round(line(straight), 4),
                 round(line(straight) / as_measured - 1, 4)])
write('limit-sensitivity.csv', ['scenario', 'lower_step', 'upper_step', 'upper_step_utilization', 'limit_rps_as_measured',
                                'upper_step_utilization_if_straight', 'limit_rps_if_straight', 'change_of_limit'], sens)
for r in steps:
    r.pop('rps_valid'); r.pop('u_raw')
write('step-summary.csv', list(steps[0].keys()), [list(r.values()) for r in steps])
write('status0-by-route.csv', ['scenario', 'step', 'route', 'requests_with_no_response'],
      [[s, st, r, n] for (s, st, r), n in sorted(by_route.items())])
write('db-util-around-window-end.csv', ['scenario', 'step', 'ten_second_bin_start_after_scenario_start_sec', 'db_utilization', 'where', 'load_tool'], bins)
write('status500-seconds.csv', ['scenario', 'step', 'route', 'requests', 'min_sec', 'mean_sec', 'max_sec'], s500)
write('status0-requests.csv', ['scenario', 'step', 'route', 'sent_after_scenario_start_sec', 'lasted_sec'], sorted(requests))
print('steps', len(steps), 'routes rows', len(by_route), 'requests', len(requests))
