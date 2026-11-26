#!/usr/bin/env python3
"""Reads the kept step records of the rehearsal (ladder/<scenario>/rung-*/record.json) and writes three tab-separated tables next to it:
steps.tsv (the figures of each step), step-times.tsv (started_at to ended_at, the window, the preparation, the part outside both, the wait at the end),
running-requests.tsv (the requests running at the two edges of the window, by route, summed).  python3 -I read_steps.py <dir with ladder/>
"""
import glob, json, os, sys, datetime
base = sys.argv[1]
def t(s): return datetime.datetime.fromisoformat(s)
steps, times, edges = [], [], []
for sc in sorted(glob.glob(base + '/ladder/s-*-30000')):
    prev_end = None
    for rd in sorted(glob.glob(sc + '/rung-*')):
        r = json.load(open(rd + '/record.json'))
        name = os.path.basename(sc).split('-30000')[0]
        steps.append([name, r['rung'], r['decided_rps'], '%.4f' % r['achieved_rps'], r['rps_status'], '%.2f' % (100 * r['db_utilization']),
                      '%.2f' % (100 * r['app_cpu_utilization']), r['requests_in_window'], r['k6_dropped_iterations'], json.dumps(r['http_status'], sort_keys=True), r['warmup_sec']])
        s, e = t(r['started_at']), t(r['ended_at'])
        dur = (e - s).total_seconds()
        w = r['window_sec']; wu = r['warmup_sec']
        eos = r.get('end_of_step') or {}
        wait = '%s finished after the window, longest %.0f ms' % (eos.get('requests_finished_after_window'), eos.get('longest_after_window_ms') or 0)
        gap = '' if prev_end is None else '%.0f' % (s - prev_end).total_seconds()
        times.append([name, r['rung'], r['decided_rps'], r['started_at'], r['ended_at'], '%.0f' % dur, w, wu, '%.0f' % (dur - w - wu), wait, gap])
        prev_end = e
        rr = r.get('running_requests') or {}
        a = sum(rr.get('at_window_start', {}).values()); b = sum(rr.get('at_window_end', {}).values())
        edges.append([name, r['rung'], r['decided_rps'], a, b])
def w(fn, head, rows):
    with open(os.path.join(base, fn), 'w') as f:
        f.write('\t'.join(head) + '\n')
        for x in rows: f.write('\t'.join(str(c) for c in x) + '\n')
w('steps.tsv', ['scenario', 'rung', 'rps_decided', 'rps_achieved', 'status', 'db_percent', 'app_cpu_percent', 'requests_in_window', 'dropped', 'http_status', 'warmup_sec'], steps)
w('step-times-steps.tsv', ['scenario', 'rung', 'rps', 'started_at', 'ended_at', 'seconds', 'window_sec', 'warmup_sec', 'outside_sec', 'end_wait_sec', 'gap_since_previous_step_sec'], times)
w('running-requests.tsv', ['scenario', 'rung', 'rps', 'running_at_window_start', 'running_at_window_end'], edges)
