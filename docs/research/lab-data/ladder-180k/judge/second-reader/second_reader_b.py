# The second reader's own recomputation of the B verdict (spec 7.2 / 7.4 B). Independent of any judge script.
import json, glob, os, sys
RUN, PRED, CALDIR = sys.argv[1], sys.argv[2], sys.argv[3]
P = json.load(open(PRED))
TH = 0.80
# naive basis: cal-1..3 (count N_0), largest planned rps total; tie -> largest observed utilization
cands = []
for c in ("cal-1", "cal-2", "cal-3"):
    r = json.load(open(f"{CALDIR}/{c}/record.json"))
    cands.append((1.0, r["db_utilization"]["utilization"], c))  # planned rps total of cal-1..3 = R_0 = 1.0
cands.sort(key=lambda x: (x[0], x[1]), reverse=True)
nb_rps, nb_u, nb_id = cands[0]
naive_per_rps = nb_u / nb_rps
out = {"naive_basis": nb_id, "naive_basis_util": nb_u, "scenarios": {}}
allrows = []
for sc in P["scenarios"]:
    sid = sc["id"]
    pst = {round(s["rps_total"], 6): s for s in sc["stages"]}
    rows = []
    for rd in sorted(glob.glob(f"{RUN}/{sid}-180000/rung-*")):
        rec = json.load(open(rd + "/record.json"))
        plan = json.load(open(rd + "/plan.json"))
        dec = rec["decided_rps"]; ach = rec["achieved_rps"]
        valid_own = abs(ach / dec - 1) <= 0.05 and rec["k6_dropped_iterations"] == 0
        m = rec["db_utilization_percent"] / 100
        st = pst[round(dec, 6)]
        p_pt = st["utilization"]["point"]
        nv = naive_per_rps * dec
        rows.append(dict(sc=sid, rung=os.path.basename(rd), rps=dec, ach=ach, valid=valid_own, flag_valid=rec["rps_valid"],
                         meas=m, pred=p_pt, naive=nv, warm_plan=plan["warmup_sec"], warm_pred=st["warmup_sec"],
                         usable=valid_own and 0.10 <= m <= 0.90))
    # measured limit: first valid rung >= TH and the valid rung just below it
    v = [r for r in rows if r["valid"]]
    lim = None; how = None
    for i, r in enumerate(v):
        if r["meas"] >= TH and i > 0 and v[i-1]["meas"] < TH:
            a, b = v[i-1], r
            lim = a["rps"] + (TH - a["meas"]) * (b["rps"] - a["rps"]) / (b["meas"] - a["meas"]); how = f"interp {a['rps']}->{b['rps']}"
            break
    if lim is None and len(v) >= 2:
        a, b = sorted(v, key=lambda r: r["rps"])[-2:]
        lim = a["rps"] + (TH - a["meas"]) * (b["rps"] - a["rps"]) / (b["meas"] - a["meas"]); how = "extrap"
    # predicted limit: point utilization is linear in rps -> solve TH directly (own), and file value
    k = [r["pred"] / r["rps"] for r in rows]
    own_pred_lim = TH / (sum(k) / len(k))
    file_pred_lim = sc["limit_rps"]["point"]["rps"]
    naive_lim = TH / naive_per_rps
    out["scenarios"][sid] = dict(n_rungs=len(rows), n_valid=len(v), meas_limit=lim, how=how,
        pred_limit_own=own_pred_lim, pred_limit_file=file_pred_lim, pred_k_spread=(max(k)-min(k))/min(k),
        rel_pred=(own_pred_lim - lim) / lim * 100 if lim else None,
        rel_naive=(naive_lim - lim) / lim * 100 if lim else None, naive_limit=naive_lim)
    allrows += rows
U = [r for r in allrows if r["usable"]]
V = [r for r in allrows if r["valid"]]
mae_p = sum(abs(r["pred"] - r["meas"]) for r in U) / len(U) * 100
mae_n = sum(abs(r["naive"] - r["meas"]) for r in U) / len(U) * 100
agree_ge = [((r["pred"] >= TH) == (r["meas"] >= TH)) for r in V]
agree_gt = [((r["pred"] > TH) == (r["meas"] > TH)) for r in V]
meas_sc = [s for s, d in out["scenarios"].items() if d["meas_limit"] is not None]
rel = {s: out["scenarios"][s]["rel_pred"] for s in meas_sc}
in20 = sum(abs(x) <= 20 for x in rel.values()); in10 = sum(abs(x) <= 10 for x in rel.values())
out.update(dict(n_all=len(allrows), n_valid=len(V), n_usable=len(U), n_meas_scen=len(meas_sc),
    undecidable=(len(U) < 10 or len(meas_sc) < 4),
    item1_pred=mae_p, item1_naive=mae_n, item1_pass=mae_p < mae_n,
    item2=mae_p, item2_min=mae_p <= 15, item2_target=mae_p <= 5,
    item3_ratio=sum(agree_ge) / len(V), item3_ratio_gt=sum(agree_gt) / len(V),
    item3_miss=[(r["sc"], r["rung"], round(r["pred"]*100, 2), round(r["meas"]*100, 2)) for r, a in zip(V, agree_ge) if not a],
    item4_rel=rel, item4_in20=in20, item4_in10=in10,
    item4_min=in20 / len(meas_sc) >= 0.8, item4_target=in10 / len(meas_sc) >= 0.8,
    warm_mismatch=[(r["sc"], r["rung"], r["warm_plan"], r["warm_pred"]) for r in allrows if r["warm_plan"] != r["warm_pred"]],
    flag_disagree=[(r["sc"], r["rung"]) for r in allrows if r["valid"] != r["flag_valid"]],
    excluded_not_usable=[(r["sc"], r["rung"], round(r["meas"]*100, 2), r["valid"]) for r in allrows if not r["usable"]]))
json.dump(dict(out, rows=allrows), open(sys.argv[4], "w"), indent=1)
for k, v in out.items():
    if k != "scenarios": print(k, ":", v)
for s, d in out["scenarios"].items(): print(s, d)
