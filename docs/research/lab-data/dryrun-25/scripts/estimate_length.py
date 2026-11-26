#!/usr/bin/env python3
"""Length of the lab backtest on Bagisto from the rehearsal's own times: a conditional table, not a prediction of any result.
  python3 -I estimate_length.py --steps step-times-steps.tsv --out judge/estimate-length.md
Step time = window + preparation (W) + c + wait. The window of step k is the rule of scheme B (the smallest multiple of 40 / rps that is at least 300 s).
c is the part of a step outside the window and the preparation (rehearsal: 60 to 64 s); the gap between two steps of a scenario is the rehearsal's median, between two scenarios its median,
and the chain's first step starts after the first recreation (rehearsal: 50 s). The wait at the end of a step is 0 in the rehearsal and is not known for a larger count.
Reads one file; no database, no clock."""
import argparse, math, statistics as st
STEPS = [0.025, 0.035, 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0]
def window(r):
    u = 40.0 / r; t = int(math.ceil(300.0 / u - 1e-9)); return int(math.floor((t if t > 1 else 1) * u + 1e-9))
def hm(s): s = int(round(s)); return '%d h %02d min' % (s // 3600, s % 3600 // 60)
ap = argparse.ArgumentParser(); ap.add_argument('--steps'); ap.add_argument('--out'); a = ap.parse_args()
rows = [l.rstrip('\n').split('\t') for l in open(a.steps)][1:]
c = [float(r[8]) for r in rows]; c_max = max(c); c_med = st.median(c)
g_in = st.median([float(r[10]) for r in rows if r[10]])
import datetime as dt
T = dt.datetime.fromisoformat
by = {}
for r in rows: by.setdefault(r[0], []).append(r)
sc = sorted(by); g_btw = st.median([(T(by[b][0][3]) - T(by[x][-1][4])).total_seconds() for x, b in zip(sc, sc[1:])])
def total(K, W, c, n_sc=5):
    return n_sc * sum(window(STEPS[i]) + W + c for i in range(K)) + n_sc * g_in * (K - 1) + g_btw * (n_sc - 1) + 50
Ks = [6, 8, 10, 12, 15]; Ws = [231, 450, 900]
L = ['# Length of the lab backtest on Bagisto, from the rehearsal (conditional)\n',
     'Rehearsal facts used (30,000 rows, 10 steps, all valid): c 60 to 64 s (median %.0f, largest %.0f); gap between two steps of a scenario median %.0f s; gap between two scenarios (end of the last step to the start of the next first step) median %.0f s; the first step starts 50 s after the chain; wait at the end of a step 0 in all 10 (no request outlived its window).\n' % (c_med, c_max, g_in, g_btw),
     'Formula: sum over the five scenarios and the steps k <= K of (window(rps_k) + W + c) + gaps, with c = %.0f s (the largest seen). Windows of the ladder: ' % c_max + ', '.join('%g rps: %d s' % (s, window(s)) for s in STEPS) + '.\n',
     '**W is the preparation of a step**, `warmup_sec` of the backtest\'s own prediction (the largest, over the routes that stay in the mix, of the upper bound of one request\'s time). In the rehearsal it was **231 s** for every stage (route `GET /api/products`, upper bound 230.95 s). **If that bound grows in proportion to the count** (a premise; the rehearsal has one count), it grows by about 77 s per 10,000 rows and passes 900 s (half of the 1,800 s request timeout, the line at which the prediction tool stops with exit 39) at about 117,000 rows. The backtest\'s count is above that, so **its prediction can stop on this line unless that route is taken out of the mix by the allocation at that count, or the bound is smaller than a straight line gives**. This is read from the backtest\'s own estimate when its prediction is made; the rehearsal cannot say which.\n',
     '| Steps per scenario K | W = 231 s | W = 450 s | W = 900 s |', '| --- | --- | --- | --- |']
for K in Ks: L.append('| %d | %s | %s | %s |' % ((K,) + tuple(hm(total(K, W, c_max)) for W in Ws)))
L += ['', 'The number of steps per scenario is decided by the limit rps of the backtest\'s prediction and by the stopping rule (a scenario goes on up the ladder until it stops: 80% utilization or a rate that does not come out); K is the same for the five scenarios in this table only for the sake of a table, and the real number differs by scenario. Each added step costs its window + W + c (+ wait): for example with W = 450 s the step at 1 rps costs 320 + 450 + 64 = 834 s.',
      'The rehearsal prediction at 30,000 rows gives limit rps (point) of 2.5 to 5.9 for the five scenarios; at a larger count the limit rps is lower, so K is smaller than the 15 steps of the ladder; the rehearsal cannot say by how much.',
      'Not included: a stop and resume (the stop mark), a restart after a reboot (the pause of the operating system\'s updates ends 2026-11-29 00:02 JST), the build and the analyze of the backtest\'s database, the preparation before the chain (build, estimate, prediction), and waits at the end of a step (0 here; up to 1,800 s per step is possible at a count where a request outlives its window). These are figures of the rehearsal; none is a figure of the backtest.']
open(a.out, 'w').write('\n'.join(L) + '\n'); print('\n'.join(L))
