#!/usr/bin/env python3
"""Length of the sixth B at 180,000 rows from the rehearsal's own times (a conditional estimate, not a prediction of any result).
  python3 -I estimate_length_w.py --steps step-times.tsv --chain-ladder <rehearsal ladder dir> --ref170 <ladder-170k dir> --out <file.md>
Reads files only. The rule of the preparation (section 7.4.5): step time = window + warmup_sec + c + wait."""
import argparse, glob, json, math, statistics as st, datetime as dt
T = dt.datetime.fromisoformat
STEPS = [0.025, 0.035, 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0]
def window(r):
    u = 40.0 / r; return int(round(math.ceil(300.0 / u - 1e-9) * u))
def hm(s): s = int(round(s)); return '%d h %02d min' % (s // 3600, s % 3600 // 60)
ap = argparse.ArgumentParser(); ap.add_argument('--steps'); ap.add_argument('--chain-ladder'); ap.add_argument('--ref170'); ap.add_argument('--out'); ap.add_argument('--rows', type=int, default=180000)
a = ap.parse_args()
rows = [l.rstrip('\n').split('\t') for l in open(a.steps)][1:]
cs = [float(r[7]) for r in rows]
c_med, c_max, c_min = st.median(cs), max(cs), min(cs)
within, between, first = [], [], None
prev_end = None; prev_sc = None
for p in sorted(glob.glob(a.chain_ladder + '/s-*/ladder.json')):
    L = json.load(open(p)); rs = L['rungs']
    for i, r in enumerate(rs):
        if i > 0: within.append((T(r['started_at']) - T(rs[i-1]['ended_at'])).total_seconds())
    if prev_end: between.append((T(rs[0]['started_at']) - prev_end).total_seconds())
    prev_end = T(rs[-1]['ended_at'])
g_in, g_btw = st.median(within), st.median(between)
# the reference: the 170,000-row record (steps per scenario, the wait at the end of each step)
K, waits = {}, []
for p in sorted(glob.glob(a.ref170 + '/s-*-170000/ladder.json')):
    L = json.load(open(p)); K[L['scenario']] = len(L['rungs'])
    waits += [r['end_of_step']['longest_after_window_ms'] / 1000.0 for r in L['rungs']]
ZIP_PER_1000 = 3.1815           # sec of the longest request's upper bound per 1,000 rows (170,000: 540.86; 300,000: 954.56)
W = int(math.ceil(ZIP_PER_1000 * a.rows / 1000.0))
def total(W, c, wait_per_step, K):
    t = 0
    for s, k in K.items():
        for i in range(k): t += window(STEPS[i]) + W + c + (wait_per_step.pop(0) if wait_per_step else 0)
        t += g_in * (k - 1)
    return t + g_btw * (len(K) - 1) + 17
nsteps = sum(K.values())
cases = [('A: the 170,000-row stops (%s), the rehearsal\'s median c, no wait' % ', '.join(map(str, K.values())), total(W, c_med, [], K)),
         ('B: as A, with the largest c of the rehearsal', total(W, c_max, [], K)),
         ('C: as A, with the end-of-step waits of the 170,000-row record in order (%d s in all)' % round(sum(waits)), total(W, c_med, list(waits), K)),
         ('D: as A, with the old preparation of 60 s (what the same steps would take without the rule)', total(60, c_med, [], K))]
L = ['# Estimate of the length of the sixth B at %s rows (from the rehearsal; conditional)\n' % format(a.rows, ','),
     'Rehearsal facts used (30,000 rows, 10 steps): c (the part of a step outside the window and the preparation) %.0f to %.0f s, median %.0f; gap between two steps of a scenario median %.0f s; gap between two scenarios (end of the last step to start of the next first step) median %.0f s; waits at the end of a step 0 in all 10.\n' % (c_min, c_max, c_med, g_in, g_btw),
     'Formula: sum over scenarios s and steps k <= K_s of (window(rps_k) + W + c + wait) + gaps. W = warmup_sec of the prediction; for the longest request (the zip export) its upper bound is about %.4f s per 1,000 rows (170,000 rows: 540.86; 300,000 rows: 954.56; recorded), so **W is about %d s at %s rows (a premise: the bound stays linear; the real W is written by the prediction of T4)**. Windows are those of the spec (1,600 s at 0.025 rps, 1,143 s at 0.035 rps, ...).\n' % (ZIP_PER_1000, W, format(a.rows, ',')),
     '| Case | Steps | Total |', '| --- | --- | --- |'] + ['| %s | %d | %s (%d s) |' % (n, nsteps, hm(t), t) for n, t in cases]
L += ['', 'Conditions: the number of steps per scenario is decided by the limit rps of the prediction (T4); the cases above use the steps of the 170,000-row record (%d steps) only as a stand-in and **the real number can differ**. Each added step costs window + W + c (+ wait). With W = %d the preparation alone adds %d x (W - 60) = %d s (%s) over the old 60 s for %d steps. '
      'The waits at the end of a step are not seen at 30,000 rows (0 in all 10); at 170,000 rows 33 of 39 steps had one (largest %.0f s), so case C is the nearer to a count at which a request outlives the window; a wait up to 1,800 s per step is possible. The rehearsal says nothing about whether the preparation removes the waits.\n' % (nsteps, W, nsteps, nsteps * (W - 60), hm(nsteps * (W - 60)), nsteps, max(waits)),
      'Not included: a stop and resume (the stop mark), a restart after a power or update reboot, the builds (about 90 s at 150,000 rows) and the preparation before the chain. These are figures of the rehearsal and of earlier records; none is a figure of the test.']
open(a.out, 'w').write('\n'.join(L) + '\n'); print('\n'.join(L))
