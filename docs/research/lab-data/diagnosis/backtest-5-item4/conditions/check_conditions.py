#!/usr/bin/env python3
"""Cross-check of the conditions of the B measurements at 90,000, 170,000 and 300,000 rows, and of the dry run at 30,000 rows.

A reading of records that already exist. It runs no measurement, calls nothing in the lab, uses no container, and changes no
prediction, estimate, calibration, formula, rule or score. It reads only committed records (and, for the dry run only, the
dry run's own rehearsal prediction when it is on disk) and writes two files next to itself:

  conditions.csv         one row per measurement: the conditions it was made under (timeouts, the wait at the end of a step,
                         the CPU and memory limits, the window, the tool, the circumstance that made the 300,000-row ladder undecidable)
  step_cpu_coverage.csv  one row per recorded step: what the DB CPU readings of the step cover, and how much DB CPU after the
                         window can be read from the records (the part inside the sampler's reach, and the part that the next
                         step's first reading gives)

It also checks, step by step, that the recorded utilisation is what the DB container's CPU readings give over the window
(the utilisation yardstick), and stops with a non-zero exit when a check does not hold.

Run:  python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/conditions/check_conditions.py
(from anywhere; the paths below are relative to the repository root and are found from this file's place)
"""
import csv
import glob
import json
import os
import re
import statistics
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..', '..', '..', '..'))
LAB = os.path.join(ROOT, 'docs', 'research', 'lab-data')


def rel(p):
    return os.path.relpath(p, ROOT).replace(os.sep, '/')


# One entry per measurement. `tool_commit` is the commit of peak-sim that held the measuring tool; `tool_source` says where that
# comes from (the records of the measurement itself, or the commit times).
ROUNDS = [
    dict(count=90000, name='B, 4th round', dir='ladder-90k', tool_commit='9f96913', tool_source='stated in ladder-90k/README.md',
         circumstance='comparable: all 5 scenarios ran to their own stop rule; one stop of about 28 hours between s-4 and s-5 (s-5 measured after the database was brought up again)'),
    dict(count=170000, name='B, 5th round', dir='ladder-170k', tool_commit='7a4cf32', tool_source='stated in ladder-170k/README.md',
         circumstance='comparable: s-3 is the second run (the first, stopped run is kept as s-3-170000-INVALID-20261119 and is not read here); s-3 to s-5 were measured after a stop and a restart of the lab'),
    dict(count=300000, name='B, 3rd round', dir='ladder-300k', tool_commit='0390da6',
         tool_source='not stated in the records; the last commit that touches poc/lab/bin, poc/lab/load or poc/lab/observe before the first scenario started (2026-11-16 09:01:52), by commit time',
         circumstance='undecidable ladder (spec 7.4.3): the load tool cut a request still running at the end of a step after its own default 30 s and counted only the requests that had finished; '
                      'GET /books/{slug} returned HTTP 500 in every step (SQLSTATE 1390, placeholder limit); 3 of 30 steps are invalid (achieved rps outside 5% of the decided one, counted by finished requests); '
                      's-5 was stopped in step 9 by the memory guard (no record for step 9)'),
    dict(count=30000, name='dry run of the 5th round (not a test)', dir='dryrun-286/b/ladder', tool_commit='797074b',
         tool_source='stated in dryrun-286/b/RECORD.md (the HEAD the throw-away copy was cloned at)',
         circumstance='rehearsal: 2 steps per scenario (0.025 and 0.035 rps), one route taken out by a made-up memory mark; not a test and no verdict uses it'),
]


def samples(path):
    out = []
    with open(path, encoding='utf-8') as fh:
        for line in fh:
            a = line.split()
            if len(a) == 2:
                out.append((float(a[0]), float(a[1])))
    return out


def interp(s, t):
    for (t0, u0), (t1, u1) in zip(s, s[1:]):
        if t0 <= t <= t1:
            return u0 + (u1 - u0) * (t - t0) / (t1 - t0)
    return None


def load(p):
    with open(p, encoding='utf-8') as fh:
        return json.load(fh)


def fnum(x, nd=6):
    if x is None:
        return 'NA'
    if isinstance(x, float):
        return ('%.' + str(nd) + 'g') % x
    return str(x)


failures = []


def check(ok, text):
    print(('OK   ' if ok else 'FAIL ') + text)
    if not ok:
        failures.append(text)


def scenario_dirs(rnd):
    base = os.path.join(LAB, rnd['dir'])
    pat = 's-[1-5]-%d' % rnd['count']
    return sorted(glob.glob(os.path.join(base, pat)))


steps = []   # all step dicts, in time order inside a scenario
rounds_info = {}
for rnd in ROUNDS:
    info = dict(prediction_files=set(), settings=set(), cpus=set(), mem_max=set(), routes=set(), http500=0, status0=0)
    for sd in scenario_dirs(rnd):
        sc = os.path.basename(sd).rsplit('-', 1)[0]
        rungs = [r for r in sorted(glob.glob(os.path.join(sd, 'rung-*'))) if os.path.exists(os.path.join(r, 'record.json'))]
        mem = open(os.path.join(sd, 'mem-baseline.txt'), encoding='utf-8').read()
        m = re.search(r'app_memory_max=(\S+)', mem)
        info['mem_max'].add(m.group(1) if m else 'NA')
        for rd in rungs:
            rec = load(os.path.join(rd, 'record.json'))
            run = load(os.path.join(rd, 'run.json'))
            s = samples(os.path.join(rd, 'cpu.txt'))
            wf, wt = run['window_epoch']['from'], run['window_epoch']['to']
            a_, b_ = interp(s, wf), interp(s, wt)
            recomputed = (b_ - a_) / 1e6 / run['window_sec'] / run['db_utilization']['cpus'] if a_ is not None and b_ is not None else None
            k6 = open(os.path.join(rd, 'k6-output.txt'), encoding='utf-8', errors='replace').read()
            inter = re.findall(r'(\d+) interrupted iterations', k6)
            interrupted = int(inter[-1]) if inter else None
            req = run['requests']
            hs = req['http_status']
            eos = rec.get('end_of_step')
            wait_s = None if eos is None else eos['longest_after_window_ms'] / 1000.0
            fin_after = None if eos is None else eos['requests_finished_after_window']
            wait_limit = None if eos is None else eos['wait_for_running_requests']
            cov = s[-1][0] - wt                      # how far the DB CPU readings reach past the window end
            after = (s[-1][1] - b_) / 1e6            # DB CPU seconds from the window end to the last reading
            last_rate = (s[-1][1] - s[-2][1]) / 1e6 / (s[-1][0] - s[-2][0])
            first_rate = (s[1][1] - s[0][1]) / 1e6 / (s[1][0] - s[0][0])   # before the step's own load starts
            tail = None if wait_s is None else max(0.0, wait_s - cov)
            st = dict(round=rnd, count=rnd['count'], run=sc, rung=os.path.basename(rd), rps=rec['decided_rps'], window_s=run['window_sec'],
                      window_from=wf, window_to=wt, util_rec=run['db_utilization']['percent'], util_re=None if recomputed is None else recomputed * 100,
                      cpus=run['db_utilization']['cpus'], delta_rec=run['db_utilization']['delta_usage_usec'],
                      delta_re=None if a_ is None else b_ - a_, n_samples=len(s), first_t=s[0][0], last_t=s[-1][0],
                      first_u=s[0][1], last_u=s[-1][1], cov=cov, after=after, last_rate=last_rate, first_rate=first_rate,
                      wait_s=wait_s, wait_limit=wait_limit, fin_after=fin_after, tail=tail, interrupted=interrupted,
                      status=hs, sent=req.get('sent_in_window'), in_window=req['in_window'], k6_timeout=req['request_timeout'],
                      started=rec['started_at'], ended=rec['ended_at'], valid=rec.get('rps_valid'),
                      rps_counted=rec.get('rps_counted'), by_route=req['http_status_by_route'])
            st['samples'] = s
            steps.append(st)
            info['prediction_files'].add(rec['prediction']['file'])
            info['settings'].add(json.dumps({k: v for k, v in rec['settings'].items() if not k.endswith('note')}, sort_keys=True))
            info['cpus'].add(run['db_utilization']['cpus'])
            info['routes'].add(len(rec['per_route']))
            info['status0'] += int(hs.get('0', 0))
            for r, d in req['http_status_by_route'].items():
                info['http500'] += int(d.get('500', 0))
    rounds_info[rnd['count']] = info

# --- next step in the same scenario, and the next scenario's first reading ---------------------------------------------------------
key = lambda st: (st['count'], st['run'])
by_scn = {}
for st in steps:
    by_scn.setdefault(key(st), []).append(st)
for k, L in by_scn.items():
    for a, b in zip(L, L[1:]):
        a['next_gap_s'] = b['first_t'] - a['last_t']
        a['next_gap_cpu_s'] = (b['first_u'] - a['last_u']) / 1e6
        a['next_first_rate'] = b['first_rate']
        a['next_window_starts_s'] = b['window_from'] - b['first_t']
        # how long DB work that was already running when the next step began goes on: the first reading after the step's start
        # at which the DB's CPU rate over the reading interval is below 0.1 cores (only for a step that began with a rate of 0.3 cores or more)
        a['next_leftover_lasts_s'] = None
        if b['first_rate'] >= 0.3:
            ss = b['samples']
            for x, y in zip(ss, ss[1:]):
                if (y[1] - x[1]) / 1e6 / (y[0] - x[0]) < 0.1:
                    a['next_leftover_lasts_s'] = x[0] - ss[0][0]
                    break
for cnt in {st['count'] for st in steps}:
    ks = sorted([k for k in by_scn if k[0] == cnt], key=lambda k: by_scn[k][0]['first_t'])
    for k1, k2 in zip(ks, ks[1:]):
        a, b = by_scn[k1][-1], by_scn[k2][0]
        gap_s, gap_cpu = b['first_t'] - a['last_t'], (b['first_u'] - a['last_u']) / 1e6
        a['scn_gap_s'], a['scn_gap_cpu_s'] = gap_s, gap_cpu

# --- checks -----------------------------------------------------------------------------------------------------------------------
print('== utilisation yardstick: the recorded utilisation against the DB container CPU readings over the window')
for rnd in ROUNDS:
    L = [s for s in steps if s['count'] == rnd['count']]
    worst = max(abs(s['delta_rec'] - s['delta_re']) for s in L)
    worst_pct = max(abs(s['util_rec'] - s['util_re']) for s in L)
    check(worst < 1.0 and worst_pct < 1e-9, '%d rows: %d steps, utilisation = (reading at window end - reading at window start) / window / %d cores, '
          'largest difference %.3g usec in the CPU time and %.3g points' % (rnd['count'], len(L), L[0]['cpus'], worst, worst_pct))
    info = rounds_info[rnd['count']]
    check(info['cpus'] == {2}, '%d rows: the DB cores in every run.json = %s' % (rnd['count'], sorted(info['cpus'])))
    check(len(info['settings']) == 1, '%d rows: one set of limits in every step: %s' % (rnd['count'], sorted(info['settings'])[0]))
    check(len(info['mem_max']) == 1, '%d rows: app container memory limit in every baseline = %s bytes' % (rnd['count'], sorted(info['mem_max'])))

print('== prediction yardstick: resources.cores of the prediction file each round was measured against')
pred_cores = {}
pred_info = {}
for rnd in ROUNDS:
    pf = sorted(rounds_info[rnd['count']]['prediction_files'])
    check(len(pf) == 1, '%d rows: one prediction file in every step: %s' % (rnd['count'], pf))
    path = os.path.join(ROOT, pf[0])
    if not os.path.exists(path):
        # the dry run's rehearsal predictions are not committed; they are kept on disk beside the dry run's records
        alt = sorted(glob.glob(os.path.join(LAB, 'dryrun-286', 'prediction', '*', os.path.basename(pf[0]))))
        path = alt[0] if alt else None
    if path is None:
        pred_cores[rnd['count']] = 'NA'
        print('NOTE %d rows: the prediction file %s is not on disk here (the dry run keeps its rehearsal predictions out of version control)' % (rnd['count'], pf[0]))
        continue
    p = load(path)
    pred_cores[rnd['count']] = p['resources']['cores']
    res = p['resources']
    dropped = sorted({d['route'] for sc in p['scenarios'] for d in sc.get('allocation', {}).get('dropped_routes', [])})
    pred_info[rnd['count']] = dict(
        cpu_time=res.get('app_cpu_time_limit_s', 'NA'), memory=res.get('app_memory_limit_bytes', 'NA'), placeholders=res.get('db_placeholder_limit', 'NA'),
        dropped=('allocation not in this prediction' if 'allocation' not in p else ('none' if not dropped else ' | '.join(dropped))))
    check(p['resources']['cores'] == 2.0, '%d rows: %s resources.cores = %s' % (rnd['count'], rel(path), p['resources']['cores']))

print('== compose: the DB container has 2 CPUs and no memory limit; the app container 6 CPUs and a memory limit')
compose = open(os.path.join(ROOT, 'poc', 'lab', 'compose', 'lab.compose.yaml'), encoding='utf-8').read()
svc = {}
cur = None
for line in compose.splitlines():
    m = re.match(r'^  ([A-Za-z0-9_-]+):\s*$', line)
    if m:
        cur = m.group(1)
        svc[cur] = []
    elif cur:
        svc[cur].append(line)
db_text = '\n'.join(svc.get('measure-db', []))
check(re.search(r'^    cpus: 2\s*$', db_text, re.M) is not None, 'measure-db (peak-lab-db): cpus: 2')
check('mem_limit' not in db_text, 'measure-db (peak-lab-db): no mem_limit line')
app_names = [n for n, t in svc.items() if 'container_name: peak-lab-app' in '\n'.join(t)]
app_text = '\n'.join(svc[app_names[0]]) if app_names else ''
check(re.search(r'^    cpus: 6\s*$', app_text, re.M) is not None and 'mem_limit' in app_text, 'app container (peak-lab-app): cpus: 6 and a mem_limit line')

# --- conditions.csv ---------------------------------------------------------------------------------------------------------------
cond_cols = ['count', 'measurement', 'records', 'scenarios', 'steps_recorded', 'steps_valid', 'first_step_started', 'last_step_ended',
             'tool_commit', 'tool_commit_source', 'prediction_file', 'prediction_cores',
             'prediction_assumed_app_cpu_time_limit_s', 'prediction_assumed_app_memory_limit_bytes', 'prediction_db_placeholder_limit', 'prediction_routes_taken_out',
             'php_max_execution_time', 'php_memory_limit', 'php_fpm_request_terminate_timeout_s', 'nginx_fastcgi_read_timeout_s', 'k6_request_timeout_s',
             'end_of_step_wait_limit_s', 'end_of_step_wait_source', 'steps_with_wait_cut_by_timeout', 'wait_cut_evidence',
             'steps_with_requests_finishing_after_window', 'longest_wait_after_window_s',
             'db_cpu_limit_cores', 'db_memory_limit', 'app_cpu_limit_cores', 'app_memory_limit_bytes',
             'window_rule', 'window_s_min', 'window_s_max', 'rps_counted', 'routes_per_step',
             'http_500_requests', 'http_status_0_requests',
             'cpu_readings_reach_after_window_s_min', 'cpu_readings_reach_after_window_s_max', 'steps_with_unreadable_wait_tail',
             'circumstance']


def secs(v):
    m = re.fullmatch(r'(\d+(?:\.\d+)?)s', str(v))
    return float(m.group(1)) if m else None


cond_rows = []
for rnd in ROUNDS:
    L = [s for s in steps if s['count'] == rnd['count']]
    info = rounds_info[rnd['count']]
    sett = json.loads(sorted(info['settings'])[0])
    waits = sorted({s['wait_limit'] for s in L if s['wait_limit']})
    cut = [s for s in L if (s['interrupted'] or 0) > 0 or int(s['status'].get('0', 0)) > 0]
    with_after = [s for s in L if (s['fin_after'] or 0) > 0] if L[0]['fin_after'] is not None else None
    tails = [s for s in L if s['tail'] is not None and s['tail'] > 0]
    counted = sorted({str(s['rps_counted']) for s in L})
    row = dict(
        count=rnd['count'], measurement=rnd['name'], records=rel(os.path.join(LAB, rnd['dir'])),
        scenarios=len({s['run'] for s in L}), steps_recorded=len(L), steps_valid=sum(1 for s in L if s['valid']),
        first_step_started=min(s['started'] for s in L), last_step_ended=max(s['ended'] for s in L),
        tool_commit=rnd['tool_commit'], tool_commit_source=rnd['tool_source'],
        prediction_file=sorted(info['prediction_files'])[0], prediction_cores=pred_cores[rnd['count']],
        prediction_assumed_app_cpu_time_limit_s=pred_info.get(rnd['count'], {}).get('cpu_time', 'NA'),
        prediction_assumed_app_memory_limit_bytes=pred_info.get(rnd['count'], {}).get('memory', 'NA'),
        prediction_db_placeholder_limit=pred_info.get(rnd['count'], {}).get('placeholders', 'NA'),
        prediction_routes_taken_out=pred_info.get(rnd['count'], {}).get('dropped', 'NA'),
        php_max_execution_time=sett['php_max_execution_time'], php_memory_limit=sett['php_memory_limit'],
        php_fpm_request_terminate_timeout_s=fnum(secs(sett['php_fpm_request_terminate_timeout'])),
        nginx_fastcgi_read_timeout_s=fnum(secs(sett['nginx_fastcgi_read_timeout'])), k6_request_timeout_s=fnum(secs(sett['k6_request_timeout'])),
        steps_with_wait_cut_by_timeout=len(cut),
        steps_with_requests_finishing_after_window='NA' if with_after is None else len(with_after),
        longest_wait_after_window_s='NA' if all(s['wait_s'] is None for s in L) else fnum(max(s['wait_s'] for s in L), 4),
        db_cpu_limit_cores=sorted(info['cpus'])[0], db_memory_limit='none (no mem_limit for the DB service)',
        app_cpu_limit_cores=6, app_memory_limit_bytes=sorted(info['mem_max'])[0],
        window_rule='smallest multiple of 40/rps seconds that is at least 300 s (spec 7.2); the first 60 s are discarded',
        window_s_min=fnum(min(s['window_s'] for s in L)), window_s_max=fnum(max(s['window_s'] for s in L)),
        rps_counted='NA (not in the record; the tool counted the requests finished when the window ended, commit 66d341e changed it)' if counted == ['None'] else ','.join(counted),
        routes_per_step=','.join(str(x) for x in sorted(info['routes'])), http_500_requests=info['http500'], http_status_0_requests=info['status0'],
        cpu_readings_reach_after_window_s_min=fnum(min(s['cov'] for s in L), 3), cpu_readings_reach_after_window_s_max=fnum(max(s['cov'] for s in L), 3),
        steps_with_unreadable_wait_tail='NA' if all(s['tail'] is None for s in L) else len(tails),
        circumstance=rnd['circumstance'])
    if waits:
        row['end_of_step_wait_limit_s'] = ','.join(fnum(secs(w)) for w in waits)
        row['end_of_step_wait_source'] = 'end_of_step.wait_for_running_requests of every record'
        row['wait_cut_evidence'] = ('none: no step has k6 interrupted iterations or an HTTP status 0; the longest wait after the window was %.0f s against a limit of %s'
                                    % (max(s['wait_s'] for s in L), ','.join(waits))) if not cut else 'see steps'
    else:
        row['end_of_step_wait_limit_s'] = '30 (k6 default graceful stop; not in the record)'
        row['end_of_step_wait_source'] = 'the load tool before commit 66d341e did not set the wait; the k6 output of every step ends with "N interrupted iterations"'
        ints = [s['interrupted'] for s in L]
        row['wait_cut_evidence'] = '%d of %d steps end with k6 interrupted iterations > 0 (largest %d); the records have no end_of_step block' % (len(cut), len(L), max(i for i in ints if i is not None))
    cond_rows.append(row)

with open(os.path.join(HERE, 'conditions.csv'), 'w', newline='', encoding='utf-8') as fh:
    w = csv.DictWriter(fh, fieldnames=cond_cols, lineterminator='\n')
    w.writeheader()
    for r in cond_rows:
        w.writerow({c: r.get(c, 'NA') for c in cond_cols})

# --- step_cpu_coverage.csv ---------------------------------------------------------------------------------------------------------
idle_rates = [s['after'] / s['cov'] for s in steps if (s['fin_after'] == 0)]
cov_cols = ['count', 'run', 'step_rps', 'window_s', 'meas_util_pct_recorded', 'meas_util_pct_from_cpu_readings', 'cpu_readings', 'cpu_readings_reach_after_window_s',
            'end_of_step_wait_s', 'requests_finished_after_window', 'k6_interrupted_iterations', 'http_status_0_requests',
            'cpu_after_window_read_s', 'cpu_rate_at_last_reading_cores', 'wait_tail_unread_s', 'next_step_gap_s', 'next_step_gap_cpu_s',
            'next_step_first_interval_rate_cores', 'next_step_leftover_lasts_s', 'next_step_window_starts_s',
            'window_cpu_s', 'spill_cpu_s', 'spill_over_window_cpu', 'spill_cpu_basis', 'spill_cpu_upper_bound_s', 'spill_missing']
cov_rows = []
for st in steps:
    row = dict(count=st['count'], run=st['run'], step_rps=st['rps'], window_s=st['window_s'], meas_util_pct_recorded=fnum(st['util_rec'], 8),
               meas_util_pct_from_cpu_readings=fnum(st['util_re'], 8), cpu_readings=st['n_samples'],
               cpu_readings_reach_after_window_s=fnum(st['cov'], 4), end_of_step_wait_s=fnum(st['wait_s'], 5) if st['wait_s'] is not None else 'NA',
               requests_finished_after_window='NA' if st['fin_after'] is None else st['fin_after'],
               k6_interrupted_iterations='NA' if st['interrupted'] is None else st['interrupted'], http_status_0_requests=int(st['status'].get('0', 0)),
               cpu_after_window_read_s=fnum(st['after'], 5), cpu_rate_at_last_reading_cores=fnum(st['last_rate'], 3),
               wait_tail_unread_s='NA' if st['tail'] is None else fnum(st['tail'], 4))
    nxt_gap = st.get('next_gap_s')
    row['next_step_gap_s'] = 'NA' if nxt_gap is None else fnum(nxt_gap, 4)
    row['next_step_gap_cpu_s'] = 'NA' if nxt_gap is None else fnum(st['next_gap_cpu_s'], 5)
    row['next_step_first_interval_rate_cores'] = 'NA' if nxt_gap is None else fnum(st['next_first_rate'], 3)
    row['next_step_leftover_lasts_s'] = 'NA' if nxt_gap is None else ('none (first interval below 0.3 cores)' if st['next_first_rate'] < 0.3 else
                                                                      ('not below 0.1 cores in the readings' if st['next_leftover_lasts_s'] is None else fnum(st['next_leftover_lasts_s'], 4)))
    row['next_step_window_starts_s'] = 'NA' if nxt_gap is None else fnum(st['next_window_starts_s'], 4)
    row['window_cpu_s'] = fnum(st['delta_rec'] / 1e6, 6)
    row['spill_cpu_upper_bound_s'] = 'NA'
    if st['tail'] is None:
        # 300,000 rows: no end_of_step block, so the length of the wait is not recorded. The load tool waited at most 30 s (its own
        # graceful stop), inside the reach of the CPU readings; a step with no interrupted iteration therefore has all its work after
        # the window inside the readings. A step with interrupted iterations has work that goes on after the cut, past the gap to the
        # next step, where it cannot be told from the next step's own load.
        if (st['interrupted'] or 0) == 0:
            row['spill_cpu_s'] = fnum(st['after'], 5)
            row['spill_cpu_basis'] = 'read after the window; no request was cut at the end of the step (k6 interrupted iterations 0) and the wait could not pass 30 s, inside the reach of the CPU readings'
            row['spill_missing'] = 'NA'
        else:
            row['spill_cpu_s'] = 'NA'
            row['spill_cpu_basis'] = 'NA'
            row['spill_missing'] = ('%d request(s) were cut by the load tool 30 s after the window; their work goes on after the wait (the end of the wait is not recorded: no end_of_step block), '
                                    'in the gap to the next step and in the next step\'s own readings, where it cannot be told from that step\'s own load. cpu_after_window_read_s is only the part read' % st['interrupted'])
    elif st['tail'] == 0:
        row['spill_cpu_s'] = fnum(st['after'], 5)
        row['spill_cpu_basis'] = 'read after the window; the wait ended inside the reach of the CPU readings'
        row['spill_missing'] = 'NA'
    elif nxt_gap is not None:
        row['spill_cpu_s'] = fnum(st['after'] + st['next_gap_cpu_s'], 5)
        row['spill_cpu_basis'] = 'read after the window plus the gap to the next step (first reading of the next step minus last reading of this step)'
        row['spill_missing'] = 'NA'
    else:
        row['spill_cpu_s'] = 'NA'
        row['spill_cpu_basis'] = 'NA'
        row['spill_missing'] = 'the CPU readings stop %.0f s after the window end and the wait ran %.0f s; the scenario has no next step to read the rest from' % (st['cov'], st['wait_s'])
        sg, sc_ = st.get('scn_gap_s'), st.get('scn_gap_cpu_s')
        if sg is not None and sc_ is not None and sc_ >= 0 and sg < 3600:
            row['spill_cpu_upper_bound_s'] = fnum(st['after'] + sc_, 5)
    try:
        row['spill_over_window_cpu'] = fnum(float(row['spill_cpu_s']) / (st['delta_rec'] / 1e6), 4)
    except ValueError:
        row['spill_over_window_cpu'] = 'NA'
    cov_rows.append(row)

with open(os.path.join(HERE, 'step_cpu_coverage.csv'), 'w', newline='', encoding='utf-8') as fh:
    w = csv.DictWriter(fh, fieldnames=cov_cols, lineterminator='\n')
    w.writeheader()
    for r in cov_rows:
        w.writerow({c: r.get(c, 'NA') for c in cov_cols})

# --- what the checks and the tables say, in short --------------------------------------------------------------------------------------
print('== summary')
print('steps read: ' + ', '.join('%d rows: %d' % (r['count'], sum(1 for s in steps if s['count'] == r['count'])) for r in ROUNDS))
print('idle reference (steps with no request finishing after the window): median %.4f cores of DB CPU in the readings after the window (%d steps)'
      % (statistics.median(idle_rates), len(idle_rates)))
for r in ROUNDS:
    L = [s for s in steps if s['count'] == r['count']]
    print('%d rows: wait cut by timeout in %d steps; requests finishing after the window in %s steps; wait tail beyond the CPU readings in %s steps'
          % (r['count'], sum(1 for s in L if (s['interrupted'] or 0) > 0 or int(s['status'].get('0', 0)) > 0),
             'NA' if L[0]['fin_after'] is None else sum(1 for s in L if (s['fin_after'] or 0) > 0),
             'NA' if all(s['tail'] is None for s in L) else sum(1 for s in L if s['tail'] and s['tail'] > 0)))
print('== work already running when the next step began (reverse spill), first reading interval of the next step')
for r in ROUNDS:
    L = [s for s in steps if s['count'] == r['count'] and 'next_first_rate' in s]
    rates = [s['next_first_rate'] for s in L]
    big = [s for s in L if s['next_first_rate'] >= 0.3]
    lasts = [s['next_leftover_lasts_s'] for s in big if s['next_leftover_lasts_s'] is not None]
    print('%d rows: %d step changes inside a scenario; first-interval rate of the next step: min %.3f, max %.3f cores; %d at or above 0.3 cores%s'
          % (r['count'], len(L), min(rates), max(rates), len(big),
             '' if not big else '; the rate fell below 0.1 cores in %d of them, %s s after the next step began (its window began %.0f s after its start); in the other %d it did not fall below 0.1 cores in the readings (the next step\'s own load went on)' % (len(lasts), ('%.0f to %.0f' % (min(lasts), max(lasts))) if lasts else 'NA', statistics.median(s['next_window_starts_s'] for s in big), len(big) - len(lasts))))
print('wrote conditions.csv and step_cpu_coverage.csv')
if failures:
    print('%d check(s) failed' % len(failures))
    sys.exit(1)
