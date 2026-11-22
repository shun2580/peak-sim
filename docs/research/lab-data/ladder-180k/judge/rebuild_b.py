#!/usr/bin/env python3
"""Independent rebuild of the B verdict (items 1-4) from each step's record.json and the committed prediction.
Written by the first reader; does not use judge_b.py or poc/lab/score. Rules: spec 7.2 and 7.4 B."""
import json, glob, os, sys
R = sys.argv[1]; P = sys.argv[2]
pred = json.load(open(P))
THR = pred['resources']['threshold']
out = {'scenarios': {}, 'rows': []}
use_pt, use_nv, steps_valid, agree, agree_nv = [], [], 0, 0, 0
lim_ok10 = lim_ok20 = lim_n = 0
nsteps = 0
for sc in pred['scenarios']:
    sid = sc['id']
    stg = {round(s['rps_total'], 6): s for s in sc['stages']}
    recs = sorted(glob.glob(f"{R}/{sid}-180000/rung-*/record.json"))
    pts = []   # (rps, util, valid)
    for f in recs:
        r = json.load(open(f)); nsteps += 1
        rps = r['decided_rps']; u = r['db_utilization']
        # validity as the spec 7.2 + 7.4.3 says: sent rps within 5% of decided, dropped 0, flagged valid
        valid = (abs(r['requests_sent_in_window'] / (rps * r['window_sec']) - 1) <= 0.05 and r['k6_dropped_iterations'] == 0)
        assert valid == bool(r['rps_valid']), (f, valid, r['rps_valid'])
        s = stg[round(rps, 6)]
        pu, nu = s['utilization']['point'], s['naive']
        pts.append((rps, u, valid))
        row = dict(scenario=sid, rps=rps, measured=u, point=pu, naive=nu, valid=valid, warmup=r['warmup_sec'], plan_warmup=s['warmup_sec'])
        if valid:
            steps_valid += 1
            agree += ((pu >= THR) == (u >= THR)); agree_nv += ((nu >= THR) == (u >= THR))
            if 0.10 <= u <= 0.90:
                use_pt.append(abs(pu - u)); use_nv.append(abs(nu - u)); row['usable'] = True
        out['rows'].append(row)
    # measured limit rps
    v = [(a, b) for a, b, ok in pts if ok]
    lim = None; how = None
    for (a1, b1), (a2, b2) in zip(v, v[1:]):
        if b1 < THR <= b2 and lim is None:
            lim = a1 + (THR - b1) * (a2 - a1) / (b2 - b1); how = 'interpolated'
    if lim is None and len(v) >= 2 and v[-1][1] < THR:
        (a1, b1), (a2, b2) = v[-2], v[-1]; lim = a1 + (THR - b1) * (a2 - a1) / (b2 - b1); how = 'extended'
    if lim is None and len(v) >= 2:   # first valid step already at/above threshold, or other
        how = 'not_measured_other'
    pl = sc['limit_rps']['point']['rps']; nl = sc['limit_rps']['naive']['rps']
    e = None if lim is None else pl / lim - 1
    en = None if lim is None else nl / lim - 1
    out['scenarios'][sid] = dict(steps=len(pts), valid=len(v), last_rps=pts[-1][0], last_util=pts[-1][1], measured_limit=lim, how=how,
                                 predicted_point=pl, rel_err=e, safe=sc['limit_rps']['safe']['rps'], naive_limit=nl, naive_rel_err=en)
    if lim is not None:
        lim_n += 1; lim_ok10 += abs(e) <= 0.10; lim_ok20 += abs(e) <= 0.20
mean = lambda x: sum(x) / len(x) if x else None
usable = len(use_pt)
out['summary'] = dict(steps=nsteps, valid=steps_valid, usable=usable, limits_measured=lim_n,
    undecidable=(usable < 10 or lim_n < 4),
    item1_point_mean_pt=100 * mean(use_pt), item1_naive_mean_pt=100 * mean(use_nv), item1_smaller=mean(use_pt) < mean(use_nv),
    item2_mean_pt=100 * mean(use_pt), item2_min=100 * mean(use_pt) <= 15, item2_goal=100 * mean(use_pt) <= 5,
    item3_agree=agree, item3_valid=steps_valid, item3_share=agree / steps_valid, item3_needed=-(-8 * steps_valid // 10), item3_naive_agree=agree_nv,
    item4_within10=lim_ok10, item4_within20=lim_ok20, item4_n=lim_n, item4_needed=-(-8 * lim_n // 10))
json.dump(out, open(sys.argv[3], 'w'), indent=1)
print(json.dumps(out['summary'], indent=1))
for sid, v in out['scenarios'].items(): print(sid, json.dumps(v))
