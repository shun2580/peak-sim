#!/usr/bin/env python3
"""Per-step and per-route tables for B at 90,000 rows (the fourth round), made from the committed records only.

    python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/90k/build_table.py

It reads (paths from the repository root, found from this file's own place):
  docs/research/lab-data/ladder-90k/s-N-90000/   ladder.json and, per step, record.json, plan.json, summary.json, cpu.txt, k6-output.txt
  poc/lab/predictions/ladder-prediction-4-b90k.json   the committed prediction (stage utilisation, calibration)
  docs/research/lab-data/estimate-90k/estimate-90000.json   the committed estimate (queries and rows of one request, per route)
and writes step_table.csv, route_table.csv, route_table_measured.csv and step_extra.csv next to itself.

It measures nothing, starts no container, changes no record, and takes no number from anywhere but the files above.
Nothing it writes is a prediction, a score or a test: the prediction, the estimate, the calibration, the formula and the
pass/fail of the backtest are as committed.

Every definition is in README.md next to this file; the short form is repeated at each function.
"""
import bisect
import csv
import glob
import json
import math
import re
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[5]
LADDER = ROOT / "docs/research/lab-data/ladder-90k"
PREDICTION = ROOT / "poc/lab/predictions/ladder-prediction-4-b90k.json"
ESTIMATE = ROOT / "docs/research/lab-data/estimate-90k/estimate-90000.json"

COUNT = 90000
SCENARIOS = ["s-1", "s-2", "s-3", "s-4", "s-5"]
# The factor of the mark's CPU-time side (poc/runtime/src/Simulate/Predict/AppLimitRisk.php, PHP_CPU_OVER_DB). It is read here only
# to turn the database work of one request into the "upper bound of one request's PHP CPU time" the way the mark does.
PHP_CPU_OVER_DB = 9.11
WARMUP_S = 60  # the window starts 60 s after the load tool's start (plan.json warmup_sec; window_epoch.from - scenario_start_ms)
# queue sign rule (README): in-flight requests, mean over the last third of the window minus mean over the middle third
QUEUE_MIN_RISE = 1.0
QUEUE_MIN_RATIO = 1.15


def rel(p):
    return str(Path(p).resolve().relative_to(ROOT))


def load(p):
    with open(p) as f:
        return json.load(f)


def read_cpu(p):
    ts, us = [], []
    for line in open(p):
        a = line.split()
        if len(a) == 2:
            ts.append(float(a[0]))
            us.append(int(a[1]))
    return ts, us


def interp(ts, us, t):
    i = bisect.bisect_left(ts, t)
    if i == 0 or i >= len(ts):
        raise ValueError("time outside the sampled range")
    t0, t1, u0, u1 = ts[i - 1], ts[i], us[i - 1], us[i]
    return u0 + (u1 - u0) * (t - t0) / (t1 - t0)


def parse_k6(path):
    """The per-second progress of the load tool: active VUs per route (= requests in flight) and iterations complete.

    The load tool stops updating a route's own count once that route's schedule is over (its line then carries a down arrow
    and the count stays at its last value while the requests drain), but the total keeps falling. For such a route the count
    is rebuilt: the total less the counts of the routes that are still updated. When more than one route is in that state
    their joint count (total less the updated routes) is exact; it is split by the rule in split_stopping()."""
    out, cur = [], None
    for line in open(path, errors="replace"):
        m = re.match(r"running \((\d+)m([\d.]+)s\), (\d+)/(\d+) VUs, (\d+) complete and (\d+) interrupted", line)
        if m:
            cur = {"t": int(m.group(1)) * 60 + float(m.group(2)), "total": int(m.group(3)), "done": int(m.group(5)), "intr": int(m.group(6)), "r": {}, "stop": set()}
            out.append(cur)
            continue
        m = re.match(r"r(\d)\s+(\S*)\s*\[.*?(\d+)/(\d+) VUs", line)
        if m and cur is not None:
            cur["r"][int(m.group(1))] = int(m.group(3))
            if m.group(2) == "\u2193":
                cur["stop"].add(int(m.group(1)))
    return out


def split_stopping(k6, slow_first):
    """Rebuild the counts of the routes that are in the stopping state (see parse_k6). Returns the number of ticks fixed and
    the number of ticks where more than one route was stopping. slow_first: route indices, slowest requests first; in a
    stopping tick the joint count goes to the routes in that order, each capped by the count it showed at the last update
    (nothing is started after the schedule is over, so a route's count can only fall)."""
    fixed = multi = 0
    cap = {}
    for p in k6:
        stop = p["stop"]
        for i in list(cap):
            if i not in stop:
                del cap[i]
        if not stop:
            continue
        others = sum(c for i, c in p["r"].items() if i not in stop)
        joint = max(0, p["total"] - others)
        if len(stop) > 1:
            multi += 1
        fixed += 1
        for i in stop:
            cap[i] = min(cap.get(i, p["r"][i]), p["r"][i])  # the value shown while stopping is the last updated one
        left = joint
        for i in [i for i in slow_first if i in stop]:
            p["r"][i] = min(left, cap[i])
            cap[i] = p["r"][i]
            left -= p["r"][i]
        if left > 0:
            sys.exit(f"stopping routes cannot hold the joint count at t={p['t']}")
    return fixed, multi


def fmt(x, nd=6):
    if x is None:
        return "NA"
    if isinstance(x, str):
        return x
    t = f"{x:.{nd}f}".rstrip("0").rstrip(".") if nd else str(x)
    return "0" if t in ("-0", "") else t


STOP_TICKS = []  # (scenario, step, ticks rebuilt, ticks with more than one stopping route)


def main():
    pred = load(PREDICTION)
    est = load(ESTIMATE)
    cq = pred["calibration"]["coefficients"]["c_q"]
    crow = pred["calibration"]["coefficients"]["c_row"]
    cores = pred["resources"]["cores"]
    pred_by = {s["id"]: s for s in pred["scenarios"]}

    # database work of one request, in CPU seconds: c_q x statements + c_row x rows read (the point reading of the estimate), and the
    # upper side (the marked route's upper rows). The upper bound of one request's PHP CPU time = 9.11 x the larger of the two.
    routes = {}
    for r in est["routes"]:
        rows = r["rows_examined"]
        up = r["rows_examined_upper"] if r["rows_examined_upper"] is not None else rows
        kp = cq * r["queries"] + crow * rows
        ku = cq * r["queries"] + crow * up
        routes[r["route"]] = {"k_point": kp, "k_upper": ku, "bound": PHP_CPU_OVER_DB * max(kp, ku)}

    # self-check: the per-request values rebuild the committed point utilisation of every stage
    worst = 0.0
    for sc in pred["scenarios"]:
        for st in sc["stages"]:
            u = sum(rps * routes[n]["k_point"] for n, rps in st["rps"].items()) / cores
            worst = max(worst, abs(u - st["utilization"]["point"]))
    print(f"self-check: committed point utilisation rebuilt from the per-request values, largest difference {worst:.3g}")
    if worst > 1e-9:
        sys.exit("the per-request values do not rebuild the committed prediction; stopped")

    steps = []  # one dict per measured step
    for sc in SCENARIOS:
        d = LADDER / f"{sc}-90000"
        lad = load(d / "ladder.json")
        nums = [rg["rung"] for rg in lad["rungs"]]
        for n in nums:
            rd = glob.glob(str(d / f"rung-{n:02d}-*"))[0]
            rec = load(Path(rd) / "record.json")
            plan = load(Path(rd) / "plan.json")
            summ = load(Path(rd) / "summary.json")
            ts, us = read_cpu(Path(rd) / "cpu.txt")
            k6 = parse_k6(Path(rd) / "k6-output.txt")
            # slowest route first = largest mean in-flight per started request over the ticks where no route was stopping
            n_it0 = {i: math.ceil(r["rate"] * (plan["duration_sec"] - r["start_offset_sec"]) / float(r["time_unit"].rstrip("s"))) for i, r in enumerate(plan["routes"])}
            live = [p for p in k6 if not p["stop"]]
            slow_first = sorted(n_it0, key=lambda i: -sum(p["r"].get(i, 0) for p in live) / n_it0[i])
            nfix, nmulti = split_stopping(k6, slow_first)
            STOP_TICKS.append((sc, n, nfix, nmulti))
            steps.append({"run": sc, "n": n, "dir": rd, "rec": rec, "plan": plan, "summ": summ, "ts": ts, "us": us, "k6": k6})

    by_key = {(s["run"], s["n"]): s for s in steps}

    # ---- the database CPU in the window, from the sampler (checked against the recorded utilisation) ----
    worst = 0.0
    for s in steps:
        w = s["rec"]["window_epoch"]
        s["t_from"], s["t_to"] = w["from"], w["to"]
        s["u_from"] = interp(s["ts"], s["us"], s["t_from"])
        s["u_to"] = interp(s["ts"], s["us"], s["t_to"])
        s["window_cpu"] = (s["u_to"] - s["u_from"]) / 1e6
        calc = s["window_cpu"] / (s["t_to"] - s["t_from"]) / cores
        worst = max(worst, abs(calc - s["rec"]["db_utilization"]))
    print(f"self-check: window utilisation rebuilt from cpu.txt, largest difference from the record {worst:.3g} (48 steps)")
    if worst > 1e-9:
        sys.exit("window utilisation does not match the record; stopped")

    # ---- the database CPU after the window ----
    for s in steps:
        eos = s["rec"]["end_of_step"]
        s["longest_after_s"] = eos["longest_after_window_ms"] / 1000.0
        s["after_n"] = eos["requests_finished_after_window"]
        s["sampled_tail_s"] = s["ts"][-1] - s["t_to"]
        s["raw_sampled"] = (s["us"][-1] - s["u_to"]) / 1e6
        s["complete_by_sampler"] = s["sampled_tail_s"] >= s["longest_after_s"]
        nxt = by_key.get((s["run"], s["n"] + 1))
        s["next_first"] = None
        if nxt is not None:
            s["next_first"] = (nxt["ts"][0], nxt["us"][0])
            s["raw_to_next"] = (nxt["us"][0] - s["u_to"]) / 1e6
            s["len_to_next"] = nxt["ts"][0] - s["t_to"]

    # idle rate of the database: the tail of the steps in which no request finished after the window (sampler basis)
    idle_steps = [s for s in steps if s["after_n"] == 0]
    idle_rate = sum(s["raw_sampled"] for s in idle_steps) / sum(s["sampled_tail_s"] for s in idle_steps)
    print(f"idle rate of the database (cores): {idle_rate:.5f} from {len(idle_steps)} steps with nothing finishing after the window")

    for s in steps:
        if s["complete_by_sampler"]:
            s["tail_end"] = s["ts"][-1]
            s["tail_raw"] = s["raw_sampled"]
            s["tail_len"] = s["sampled_tail_s"]
            s["tail_source"] = "sampler_end"
        elif s["next_first"] is not None:
            s["tail_end"] = s["next_first"][0]
            s["tail_raw"] = s["raw_to_next"]
            s["tail_len"] = s["len_to_next"]
            s["tail_source"] = "next_step_first_sample"
        else:
            s["tail_raw"] = None
            s["tail_source"] = "none"
        if s["tail_raw"] is not None:
            s["spill_net"] = s["tail_raw"] - idle_rate * s["tail_len"]
        # a lower bound that is always available: what the sampler saw after the window, less the idle share of that length
        s["spill_lower_bound"] = s["raw_sampled"] - idle_rate * s["sampled_tail_s"]

    # ---- the load tool's per-second progress ----
    for s in steps:
        k6 = s["k6"]
        W = s["rec"]["window_sec"]
        a, b = WARMUP_S, WARMUP_S + W

        def mean_in(lo, hi):
            xs = [sum(p["r"].values()) for p in k6 if lo <= p["t"] < hi]
            return sum(xs) / len(xs) if xs else float("nan")

        s["infl"] = (mean_in(a, a + W / 3), mean_in(a + W / 3, a + 2 * W / 3), mean_in(a + 2 * W / 3, b))
        dt = (k6[-1]["t"] - k6[0]["t"]) / (len(k6) - 1)
        # each reading counts for the time to the next reading (the readings are 0.2 to 1.1 s apart, 1 s on average)
        wts = [k6[j + 1]["t"] - k6[j]["t"] for j in range(len(k6) - 1)] + [dt]
        s["mean_req_s"] = sum(sum(p["r"].values()) * w for p, w in zip(k6, wts)) / k6[-1]["done"]
        s["k6_total"] = k6[-1]["done"]
        s["k6_interrupted"] = k6[-1]["intr"]
        s["k6_dt"] = dt
        # mean time in the system of the requests of each route (Little's law): area of the route's in-flight series over its iteration count.
        # The iteration count of a route is ceil(rate x (duration - start offset) / time unit) (constant arrival rate; this rebuilds the
        # load tool's own total "complete" in all 48 steps, checked here). Warm-up requests are included.
        s["route_meas"] = {}
        iters = {}
        for i, r in enumerate(s["plan"]["routes"]):
            unit = float(r["time_unit"].rstrip("s"))
            n_it = math.ceil(r["rate"] * (s["plan"]["duration_sec"] - r["start_offset_sec"]) / unit)
            iters[i] = n_it
            area = sum(p["r"].get(i, 0) * w for p, w in zip(k6, wts))
            s["route_meas"][r["route"]] = area / n_it
        if sum(iters.values()) != s["k6_total"]:
            sys.exit(f"iteration counts of {s['run']} step {s['n']} do not rebuild the load tool's total")
        s["queue_sign"] = "yes" if (s["infl"][2] - s["infl"][1] >= QUEUE_MIN_RISE and s["infl"][2] >= QUEUE_MIN_RATIO * s["infl"][1]) else "no"
    # reference: the median of the mean request time over the steps of the same scenario in which no request finished after the window
    # (no request ran past the window; the first step is often slower: the containers were just recreated)
    base = {}
    for sc in SCENARIOS:
        xs = sorted(s["mean_req_s"] for s in steps if s["run"] == sc and s["after_n"] == 0)
        base[sc] = xs[len(xs) // 2] if len(xs) % 2 else (xs[len(xs) // 2 - 1] + xs[len(xs) // 2]) / 2

    # ---- the tables ----
    step_rows, route_rows, extra_rows, route_meas_rows = [], [], [], []
    worst_work = 0.0
    worst_share = 0.0
    for s in steps:
        rec, plan = s["rec"], s["plan"]
        sc_pred = pred_by[s["run"]]
        st = [x for x in sc_pred["stages"] if abs(x["rps_total"] - rec["decided_rps"]) < 1e-9]
        if len(st) != 1:
            sys.exit(f"no single prediction stage for {s['run']} step {s['n']}")
        st = st[0]
        pred_util = st["utilization"]["point"] * 100
        meas_util = rec["db_utilization_percent"]
        W = rec["window_sec"]
        sent = {r["route"]: r["requests_sent"] for r in rec["per_route"]}
        share = {r["route"]: r["share"] for r in plan["routes"]}
        sent_total = sum(sent.values())
        if sent_total != rec["requests_sent_in_window"]:
            sys.exit("sent counts do not add up")
        pred_work = 0.0
        wsum = 0.0
        wmeas = 0.0
        for name in sorted(sent):
            k = routes[name]
            pw = sent[name] * k["k_point"]
            pred_work += pw
            req_t = k["bound"]
            wsum += share[name] * req_t
            route_rows.append({
                "count": COUNT, "run": s["run"], "step_rps": rec["decided_rps"], "route": name, "sent_requests": sent[name],
                "pred_work_per_req_cpu_s": fmt(k["k_point"], 6), "pred_work_cpu_s": fmt(pw, 4),
                "req_time_s": fmt(req_t, 4), "req_time_source": "upper_bound", "req_over_window": fmt(req_t / W, 6),
                "weight": fmt(share[name], 6),
            })
            worst_share = max(worst_share, abs(share[name] - sent[name] / sent_total))
            mt = s["route_meas"][name]
            route_meas_rows.append({
                "count": COUNT, "run": s["run"], "step_rps": rec["decided_rps"], "route": name, "sent_requests": sent[name],
                "pred_work_per_req_cpu_s": fmt(k["k_point"], 6), "pred_work_cpu_s": fmt(pw, 4),
                "req_time_s": fmt(mt, 3), "req_time_source": "measured", "req_over_window": fmt(mt / W, 6),
                "weight": fmt(share[name], 6),
            })
            wmeas += share[name] * mt
        weighted = wsum / W
        worst_work = max(worst_work, abs(pred_work - pred_util / 100 * cores * W) / (pred_util / 100 * cores * W))

        if s["tail_raw"] is None:
            spill = None
            missing = (
                f"the DB CPU sampler stopped {s['sampled_tail_s']:.1f} s after the window end but the last request ended "
                f"{s['longest_after_s']:.1f} s after it, and the scenario has no later step whose first sample could close the interval "
                f"(the record after the scenario's last step is not a clean interval: table-row counts and write counters are read between scenarios); sampled part after the window "
                f"{s['raw_sampled']:.2f} CPU-s raw, {s['spill_lower_bound']:.2f} net of idle = a lower bound, not the spill"
            )
        else:
            spill = s["spill_net"]
            missing = ""
        infl = s["infl"]
        evidence = (
            f"in-flight requests (load tool, 1 s) mean by thirds of the window {infl[0]:.2f}/{infl[1]:.2f}/{infl[2]:.2f}; "
            f"mean request time {s['mean_req_s']:.1f} s (reference of {s['run']}: {base[s['run']]:.1f} s, x{s['mean_req_s'] / base[s['run']]:.2f}); "
            f"longest window request {s['summ']['duration_ms']['max'] / 1000:.1f} s; finished after window {s['after_n']}; "
            f"sign = last-third minus middle-third mean >= {QUEUE_MIN_RISE:g} and >= {QUEUE_MIN_RATIO:g}x"
        )
        step_rows.append({
            "count": COUNT, "run": s["run"], "step_rps": rec["decided_rps"], "window_s": W,
            "pred_util_pct": fmt(pred_util, 4), "meas_util_pct": fmt(meas_util, 4), "diff_pt": fmt(pred_util - meas_util, 4),
            "sent_requests": sent_total, "pred_work_cpu_s": fmt(pred_work, 3),
            "after_window_requests": s["after_n"], "max_end_of_step_delay_s": fmt(s["longest_after_s"], 3),
            "spill_work_cpu_s": fmt(spill, 3) if spill is not None else "NA", "spill_missing": missing,
            "weighted_req_over_window": fmt(weighted, 6), "queue_sign": s["queue_sign"], "queue_evidence": evidence,
            "weighted_measured_req_over_window": fmt(wmeas / W, 6),
        })
        wcpu = s["window_cpu"]
        extra_rows.append({
            "count": COUNT, "run": s["run"], "step": s["n"], "step_rps": rec["decided_rps"], "achieved_rps": rec["achieved_rps"],
            "window_s": W, "window_cpu_s": fmt(wcpu, 3),
            "window_capacity_cpu_s": fmt(cores * W, 1),
            "sampled_tail_s": fmt(s["sampled_tail_s"], 2), "last_request_end_after_window_s": fmt(s["longest_after_s"], 2),
            "tail_complete_by_sampler": "yes" if s["complete_by_sampler"] else "no",
            "tail_raw_sampled_cpu_s": fmt(s["raw_sampled"], 3),
            "tail_source": s["tail_source"], "tail_len_s": fmt(s.get("tail_len"), 2) if s["tail_raw"] is not None else "NA",
            "tail_raw_cpu_s": fmt(s["tail_raw"], 3) if s["tail_raw"] is not None else "NA",
            "idle_rate_cores": fmt(idle_rate, 5),
            "spill_net_cpu_s": fmt(s.get("spill_net"), 3) if s["tail_raw"] is not None else "NA",
            "spill_lower_bound_cpu_s": fmt(s["spill_lower_bound"], 3),
            "tail_raw_to_next_start_cpu_s": fmt(s.get("raw_to_next"), 3) if s["next_first"] is not None else "NA",
            "tail_len_to_next_start_s": fmt(s.get("len_to_next"), 2) if s["next_first"] is not None else "NA",
            "window_plus_spill_over_pred_work": fmt((wcpu + s["spill_net"]) / pred_work, 4) if s["tail_raw"] is not None else "NA",
            "window_over_pred_work": fmt(wcpu / pred_work, 4),
            "mean_req_time_s": fmt(s["mean_req_s"], 2), "mean_req_time_over_reference": fmt(s["mean_req_s"] / base[s["run"]], 3),
            "mean_req_time_over_window": fmt(s["mean_req_s"] / W, 5),
            "weighted_measured_req_over_window": fmt(wmeas / W, 6),
            "zip_measured_req_time_s": fmt(s["route_meas"]["GET /books/{bookSlug}/export/zip"], 2),
            "book_page_measured_req_time_s": fmt(s["route_meas"]["GET /books/{slug}"], 2),
            "longest_window_request_s": fmt(s["summ"]["duration_ms"]["max"] / 1000, 2),
            "p95_window_request_s": fmt(s["summ"]["duration_ms"]["p95"] / 1000, 2),
            "inflight_mean_third1": fmt(infl[0], 3), "inflight_mean_third2": fmt(infl[1], 3), "inflight_mean_third3": fmt(infl[2], 3),
            "k6_iterations_total": s["k6_total"], "k6_interrupted": s["k6_interrupted"],
        })
    # self-check of the rebuilt in-flight counts: no route's mean request time may exceed the longest request of the step
    # (summary.json duration_ms max; it covers the window's requests, the mean also the warm-up's)
    over = [(s["run"], s["rec"]["decided_rps"], name, t, s["summ"]["duration_ms"]["max"] / 1000)
            for s in steps for name, t in s["route_meas"].items() if t > s["summ"]["duration_ms"]["max"] / 1000]
    over_tick = [o for o in over if o[3] - o[4] > 1.0]
    print(f"self-check: measured route rows with a mean request time above the step's longest request: {len(over)} of {sum(len(s['route_meas']) for s in steps)}"
          f" (largest excess {max([o[3] - o[4] for o in over] or [0]):.3f} s); above it by more than one reading interval (1 s): {len(over_tick)}")
    print(f"self-check: ticks rebuilt because a route was stopping: {sum(x[2] for x in STOP_TICKS)} in {sum(1 for x in STOP_TICKS if x[2])} steps; ticks with more than one stopping route: {sum(x[3] for x in STOP_TICKS)}")
    print(f"self-check: predicted work (sent x per-request value) against predicted utilisation x cores x window, largest relative difference {worst_work:.3g}")
    print(f"check: the scenario's share against the sent share, largest difference {worst_share:.3g}")

    def write(name, rows):
        with open(HERE / name, "w", newline="") as f:
            w = csv.DictWriter(f, fieldnames=list(rows[0].keys()), lineterminator="\n")
            w.writeheader()
            w.writerows(rows)

    write("step_table.csv", step_rows)
    write("route_table.csv", route_rows)
    write("route_table_measured.csv", route_meas_rows)
    write("step_extra.csv", extra_rows)
    print(f"wrote {len(step_rows)} steps, {len(route_rows)} route rows, {len(extra_rows)} extra rows")
    print(f"interrupted iterations in any step: {sum(s['k6_interrupted'] for s in steps)}; steps with a cut wait: 0 if this is 0")
    print(f"steps by tail source: " + ", ".join(f"{k}={sum(1 for s in steps if s['tail_source'] == k)}" for k in ("sampler_end", "next_step_first_sample", "none")))


if __name__ == "__main__":
    main()
