#!/usr/bin/env python3
"""Step and route tables of the dry run at 30,000 rows (reference only; not a test, not a score).

A reading of records that already exist. It runs no measurement, calls nothing in the lab, uses no container, and changes no
prediction, estimate, calibration, formula, rule or score. It reads the dry run's step records
(docs/research/lab-data/dryrun-286/b/ladder/s-N-30000/rung-*/) and the dry run's own rehearsal prediction and estimate, which are kept
on disk beside the records (docs/research/lab-data/dryrun-286/prediction/) and are not committed. When the rehearsal prediction is not on disk the
columns that come from it are written NA with the reason, and the columns that come from the step records are still written.

It writes step_table.csv and route_table.csv next to itself. The columns are the ones every count's table uses.

Run:  python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/dryrun/build_table.py
"""
import csv
import glob
import hashlib
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..', '..', '..', '..'))
LAB = os.path.join(ROOT, 'docs', 'research', 'lab-data')
COUNT = 30000
STEP_COLS = ['count', 'run', 'step_rps', 'window_s', 'pred_util_pct', 'meas_util_pct', 'diff_pt', 'sent_requests', 'pred_work_cpu_s', 'after_window_requests',
             'max_end_of_step_delay_s', 'spill_work_cpu_s', 'spill_missing', 'weighted_req_over_window', 'queue_sign', 'queue_evidence']
ROUTE_COLS = ['count', 'run', 'step_rps', 'route', 'sent_requests', 'pred_work_per_req_cpu_s', 'pred_work_cpu_s', 'req_time_s', 'req_time_source', 'req_over_window', 'weight']


def load(p):
    with open(p, encoding='utf-8') as fh:
        return json.load(fh)


def samples(p):
    out = []
    with open(p, encoding='utf-8') as fh:
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


def g(x, nd=8):
    if x is None:
        return 'NA'
    if isinstance(x, float):
        return ('%.' + str(nd) + 'g') % x
    return str(x)


def main():
    scn_dirs = sorted(glob.glob(os.path.join(LAB, 'dryrun-286', 'b', 'ladder', 's-[1-5]-%d' % COUNT)))
    if not scn_dirs:
        print('no step records of the dry run found', file=sys.stderr)
        return 2
    first = load(os.path.join(sorted(glob.glob(os.path.join(scn_dirs[0], 'rung-*')))[0], 'record.json'))
    pred_name = os.path.basename(first['prediction']['file'])
    cand = sorted(glob.glob(os.path.join(LAB, 'dryrun-286', 'prediction', '*', pred_name)))
    pred = load(cand[0]) if cand else None
    pred_path = os.path.relpath(cand[0], ROOT).replace(os.sep, '/') if cand else None
    pred_missing = None if pred else 'the dry run\'s rehearsal prediction (%s) is not on disk beside the records; it is kept out of version control' % pred_name
    est = None
    est_note = None
    if pred:
        want = pred['inputs']['estimate_sha256']
        ecand = [p for p in glob.glob(os.path.join(LAB, 'dryrun-286', 'prediction', '*', 'estimate-%d.json' % COUNT))
                 if hashlib.sha256(open(p, 'rb').read()).hexdigest() == want]
        if ecand:
            est = load(ecand[0])
            est_note = os.path.relpath(ecand[0], ROOT).replace(os.sep, '/')
        else:
            pred_missing_est = 'the estimate the rehearsal prediction read (sha256 %s) is not on disk' % want[:12]
    cq = cr = cores = None
    per_req = {}
    if pred and est:
        cq = float(pred['calibration']['coefficients']['c_q'])
        cr = float(pred['calibration']['coefficients']['c_row'])
        cores = float(pred['resources']['cores'])
        for r in est['routes']:
            per_req[r['route']] = cq * float(r['queries']) + cr * float(r['rows_examined'])
        # self-check: the per-request values rebuild the prediction's point utilisation at every stage of every scenario
        worst = 0.0
        for sc in pred['scenarios']:
            for stg in sc['stages']:
                u = sum(rps * per_req[rt] for rt, rps in stg['rps'].items()) / cores
                worst = max(worst, abs(u - stg['utilization']['point']))
        print('self-check: per-request values rebuild the prediction\'s point utilisation at all stages, largest difference %.3g' % worst)
        if worst > 1e-9:
            print('FAIL: the per-request values do not rebuild the prediction', file=sys.stderr)
            return 1

    step_rows, route_rows = [], []
    for sd in scn_dirs:
        run = os.path.basename(sd).rsplit('-', 1)[0]
        scn_pred = None
        if pred:
            scn_pred = [s for s in pred['scenarios'] if s['id'] == run][0]
        # the only per-route time the prediction gives: the php CPU time of the longest request (scenario's allocation block)
        longest = scn_pred['allocation'].get('longest_request') if scn_pred else None
        for rd in sorted(glob.glob(os.path.join(sd, 'rung-*'))):
            if not os.path.exists(os.path.join(rd, 'record.json')):
                continue
            rec = load(os.path.join(rd, 'record.json'))
            rj = load(os.path.join(rd, 'run.json'))
            sj = load(os.path.join(rd, 'summary.json'))
            s = samples(os.path.join(rd, 'cpu.txt'))
            wt = rj['window_epoch']['to']
            after = (s[-1][1] - interp(s, wt)) / 1e6
            eos = rec['end_of_step']
            delay = eos['longest_after_window_ms'] / 1000.0
            reach = s[-1][0] - wt
            rps = rec['decided_rps']
            window = rec['window_sec']
            meas = rec['db_utilization_percent']
            # predicted stage of this step
            stage = None
            if scn_pred:
                for stg in scn_pred['stages']:
                    if abs(stg['rps_total'] - rps) < 1e-12:
                        stage = stg
            pred_util = None if stage is None else stage['utilization']['point'] * 100.0
            sent_total = rec['requests_sent_in_window']
            pred_work = None
            if stage is not None and per_req:
                pred_work = sum(pr['requests_sent'] * per_req[pr['route']] for pr in rec['per_route'])
            # spill: the wait ended inside the reach of the CPU readings (delay <= reach), so the CPU read after the window is all there is
            if delay <= reach:
                spill, missing = after, 'NA'
            else:
                spill, missing = None, 'the wait (%.0f s) is longer than the reach of the CPU readings (%.0f s)' % (delay, reach)
            dur_max = sj['duration_ms']['max'] / 1000.0
            no_queue = eos['requests_finished_after_window'] == 0 and rec['k6_dropped_iterations'] == 0
            step_rows.append(dict(
                count=COUNT, run=run, step_rps=g(rps), window_s=window,
                pred_util_pct=g(pred_util) if pred_util is not None else 'NA', meas_util_pct=g(meas),
                diff_pt=g(pred_util - meas) if pred_util is not None else 'NA',
                sent_requests=sent_total, pred_work_cpu_s=g(pred_work) if pred_work is not None else 'NA',
                after_window_requests=eos['requests_finished_after_window'], max_end_of_step_delay_s=g(delay),
                spill_work_cpu_s=g(spill) if spill is not None else 'NA', spill_missing=missing,
                weighted_req_over_window='NA',
                queue_sign='no' if no_queue else 'NA',
                queue_evidence=('no request was still running at the window end (requests_finished_after_window 0), k6 dropped 0, the longest request took %.1f s against a window of %d s; '
                                'the record holds step-level durations only (average, 95th percentile, maximum), not each request\'s delay over time' % (dur_max, window))
                if no_queue else 'NA'))
            for pr in rec['per_route']:
                r = pr['route']
                w = pr['target_rps'] / rps
                is_longest = longest is not None and longest['route'] == r
                t = float(longest['php_cpu_bound_s']) if is_longest else None
                route_rows.append(dict(
                    count=COUNT, run=run, step_rps=g(rps), route=r, sent_requests=pr['requests_sent'],
                    pred_work_per_req_cpu_s=g(per_req[r]) if r in per_req else 'NA',
                    pred_work_cpu_s=g(pr['requests_sent'] * per_req[r]) if r in per_req else 'NA',
                    req_time_s=g(t) if t is not None else 'NA', req_time_source='upper_bound' if t is not None else 'NA',
                    req_over_window=g(t / window) if t is not None else 'NA', weight=g(w)))

    with open(os.path.join(HERE, 'step_table.csv'), 'w', newline='', encoding='utf-8') as fh:
        wr = csv.DictWriter(fh, fieldnames=STEP_COLS, lineterminator='\n')
        wr.writeheader()
        wr.writerows(step_rows)
    with open(os.path.join(HERE, 'route_table.csv'), 'w', newline='', encoding='utf-8') as fh:
        wr = csv.DictWriter(fh, fieldnames=ROUTE_COLS, lineterminator='\n')
        wr.writeheader()
        wr.writerows(route_rows)
    print('steps %d, route rows %d' % (len(step_rows), len(route_rows)))
    print('prediction: %s' % (pred_path or pred_missing))
    print('estimate: %s' % (est_note or 'NA'))
    return 0


if __name__ == '__main__':
    sys.exit(main())
