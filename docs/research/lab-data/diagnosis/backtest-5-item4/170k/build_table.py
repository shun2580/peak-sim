#!/usr/bin/env python3
"""Per-step tables of B at 170,000 rows (the fifth round), read from the records only.

Run from anywhere:  python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/170k/build_table.py

It reads (paths from the repository root):
  poc/lab/predictions/ladder-prediction-5-b170k.json            the committed prediction
  docs/research/lab-data/estimate-170k/170k/estimate.json       the estimate the prediction was made from (sha256 is checked)
  docs/research/lab-data/ladder-170k/s-N-170000/                the five valid ladders (ladder.json, rung-NN-<rps>/)
The first, stopped run of s-3 (s-3-170000-INVALID-20261119) is never read: it is not used for pass or fail.

It writes next to itself: step_table.csv, route_table.csv, README.md. It measures nothing, touches no container and changes
no existing file; every number is a value in a record, or an arithmetic on such values that the README names.
"""
import bisect
import csv
import hashlib
import json
import math
import re
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[5]
PRED = ROOT / "poc/lab/predictions/ladder-prediction-5-b170k.json"
EST = ROOT / "docs/research/lab-data/estimate-170k/170k/estimate.json"
LAD = ROOT / "docs/research/lab-data/ladder-170k"
COUNT = 170000
SCENARIOS = ["s-1", "s-2", "s-3", "s-4", "s-5"]
# The factor of the CPU-time mark (simulator-spec section 6.4; poc/runtime/src/Simulate/Predict/AppLimitRisk.php PHP_CPU_OVER_DB).
PHP_CPU_OVER_DB = 9.11


def rel(p):
    return str(Path(p).resolve().relative_to(ROOT))


def sha256(p):
    return hashlib.sha256(Path(p).read_bytes()).hexdigest()


pred = json.loads(PRED.read_text())
est = json.loads(EST.read_text())
checks = []

# --- the inputs are the ones the prediction names -------------------------------------------------------------------
est_sha = sha256(EST)
checks.append(("estimate sha256 equals the one the prediction names", est_sha == pred["inputs"]["estimate_sha256"]))
checks.append(("estimate is for 170,000 rows", est["at"] == COUNT and pred["estimate_at"] == COUNT))
cq = pred["calibration"]["coefficients"]["c_q"]
cr = pred["calibration"]["coefficients"]["c_row"]
cores = float(pred["resources"]["cores"])
routes = {r["route"]: r for r in est["routes"]}


def work(route, upper=False):
    """The database CPU seconds the model gives for ONE request: c_q x statements + c_row x rows read."""
    r = routes[route]
    rows = r["rows_examined_upper"] if (upper and r["rows_examined_upper"] is not None) else r["rows_examined"]
    return cq * r["queries"] + cr * rows


def bound(route):
    """The model's php CPU time bound for one request (the CPU-time mark's bound): 9.11 x the larger of point and upper side."""
    return PHP_CPU_OVER_DB * max(work(route), work(route, True))


zip_route = "GET /books/{bookSlug}/export/zip"
# the one number the prediction file itself records for this (the longest request of every scenario): reproduce it
rec_long = pred["scenarios"][0]["allocation"]["longest_request"]
checks.append(
    (
        "reproduced php CPU bound of the longest route equals the prediction's own record",
        rec_long["route"] in routes and abs(bound(rec_long["route"]) - rec_long["php_cpu_bound_s"]) < 1e-6,
    )
)

# the model's work per request reproduces the prediction's point utilisation at every stage of every scenario
_maxdiff = 0.0
for _s in pred["scenarios"]:
    for _st in _s["stages"]:
        _u = sum(rps * work(rt) for rt, rps in _st["rps"].items()) / cores
        _maxdiff = max(_maxdiff, abs(_u - _st["utilization"]["point"]))
checks.append((f"sum of (rps x work per request) / cores reproduces the prediction's point utilisation at all 75 stages (max difference {_maxdiff:.2e})", _maxdiff < 1e-9))

# --- helpers over a rung record ---------------------------------------------------------------------------------------


def read_samples(path):
    out = []
    for line in Path(path).read_text().splitlines():
        p = line.split()
        if len(p) == 2:
            out.append((float(p[0]), int(p[1])))
    return out


def usage_at(samples, t):
    """Cumulative usage_usec at epoch t, linear between the two neighbouring samples (None outside the sampled range)."""
    if t < samples[0][0] or t > samples[-1][0]:
        return None
    for (t0, u0), (t1, u1) in zip(samples, samples[1:]):
        if t0 <= t <= t1:
            return u0 + (u1 - u0) * (t - t0) / (t1 - t0) if t1 > t0 else u0
    return None


# --- the load tool's progress lines: the number of running requests per route, about once a second ---------------------------
K6_HDR = re.compile(r"^running \((?:(\d+)m)?(\d+(?:\.\d+)?)s\), (\d+)/(\d+) VUs, (\d+) complete and (\d+) interrupted")
K6_ROW = re.compile(r"^(r\d+)\s+(\S+)?\s*\[\s*(\d+)% \]\s+(?:(\d+)/(\d+) VUs|waiting)")
# line of the one-request-time reading that was fixed before the numbers were read: the second half of the window against the first
QUEUE_RATIO_LINE = 1.20


def parse_k6(path):
    blocks, cur = [], None
    for ln in Path(path).read_text(encoding="utf-8").splitlines():
        m = K6_HDR.match(ln)
        if m:
            cur = {"t": int(m.group(1) or 0) * 60 + float(m.group(2)), "tot": int(m.group(3)), "done": int(m.group(5)) + int(m.group(6)), "rows": {}}
            blocks.append(cur)
            continue
        m = K6_ROW.match(ln)
        if m and cur is not None:
            cur["rows"][m.group(1)] = (m.group(2), m.group(4))
    return blocks


def unit_seconds(txt):
    m = re.fullmatch(r"(\d+(?:\.\d+)?)(ms|s|m|h)", txt)
    return float(m.group(1)) * {"ms": 0.001, "s": 1.0, "m": 60.0, "h": 3600.0}[m.group(2)]


def k6_measure(path, plan, window_epoch, scenario_start_s):
    """Running requests per route from the load tool's progress lines, and what follows from them.

    The progress lines (about once a second) show, for each route's scenario (r0, r1, ... = the routes of plan.json in order), how many of its
    requests are running. Once a route's schedule has ended (marked with a down arrow) the load tool stops updating that route's number and
    prints the last one again, while the total in the header line still falls; in those lines the route's number is the total minus the other
    routes' numbers (a route marked done or waiting, or not listed, has 0). Arrivals are the constant-arrival schedule of the plan.
    """
    blocks = parse_k6(path)
    prs = plan["routes"]
    R = len(prs)
    dur = float(plan["duration_sec"])
    arr = []
    for pr in prs:
        step = unit_seconds(pr["time_unit"]) / pr["rate"]
        assert abs(1.0 / step - pr["rps"]) <= 0.01 * pr["rps"], pr  # the plan's rate and time unit are whole numbers: they round the route's rps
        ts, k = [], 0
        while pr["start_offset_sec"] + k * step < dur - 1e-9:
            ts.append(pr["start_offset_sec"] + k * step)
            k += 1
        arr.append(ts)
    series, series_lo, series_hi, multi_down, no_down_mismatch, down_blocks = [], [], [], 0, 0, 0
    for b in blocks:
        n, down = [0.0] * R, []
        lo, hi = None, None
        for key, (sym, v) in b["rows"].items():
            i = int(key[1:])
            if sym == "\u2193":
                down.append(i)
            elif v is not None:
                n[i] = float(int(v))
        if down:
            down_blocks += 1
            rem = b["tot"] - sum(n)
            assert rem >= -1e-9, (path, b["t"])
            stale = [float(int(b["rows"]["r%d" % i][1])) for i in down]
            if len(down) == 1:
                n[down[0]] = rem
            else:
                # several routes past their schedule in one line: the rest is shared in proportion to the stale numbers (the reading used),
                # and, as bounds that hold whatever the split: a route past its schedule can only have fewer running than its last printed number
                # (upper = the smaller of that and the rest) and the others can have at most theirs (lower = the rest minus their last numbers)
                multi_down += 1
                lo, hi = list(n), list(n)
                for i, w in zip(down, stale):
                    n[i] = rem * w / sum(stale)
                    hi[i] = min(w, rem)
                    lo[i] = max(0.0, rem - (sum(stale) - w))
        elif abs(sum(n) - b["tot"]) > 1e-9:
            no_down_mismatch += 1
        series.append(n)
        series_lo.append(lo or n)
        series_hi.append(hi or n)
    assert no_down_mismatch == 0, (path, no_down_mismatch)
    assert blocks[-1]["tot"] == 0 and blocks[-1]["done"] == sum(len(a) for a in arr), (path, blocks[-1]["done"], sum(len(a) for a in arr))
    t = [b["t"] for b in blocks]
    area = [sum(series[i][r] * (t[i + 1] - t[i]) for i in range(len(t) - 1)) for r in range(R)]
    area_lo = [sum(series_lo[i][r] * (t[i + 1] - t[i]) for i in range(len(t) - 1)) for r in range(R)]
    area_hi = [sum(series_hi[i][r] * (t[i + 1] - t[i]) for i in range(len(t) - 1)) for r in range(R)]
    # one request at a time, first in first out: the k-th request to end is the k-th one that arrived (departures from arrivals - running)
    w0 = window_epoch["from"] - scenario_start_s
    w1 = window_epoch["to"] - scenario_start_s
    per = []
    for r in range(R):
        dmax, deps, k = 0.0, [], 0
        for i in range(len(t)):
            dmax = max(dmax, bisect.bisect_right(arr[r], t[i]) - series[i][r])
            while k < len(arr[r]) and dmax >= k + 1 - 1e-9:
                deps.append(((t[i - 1] if i else 0.0) + t[i]) / 2.0)
                k += 1
        assert len(deps) == len(arr[r]), (path, r)
        durs = [max(0.0, d - a) for a, d in zip(arr[r], deps)]
        mid = (w0 + w1) / 2.0
        first = [d for a, d in zip(arr[r], durs) if w0 <= a < mid]
        second = [d for a, d in zip(arr[r], durs) if mid <= a < w1]
        inwin = [i for i in range(len(t)) if w0 <= t[i] < w1]
        h = len(inwin) // 2
        per.append(
            {
                "area": area[r], "arrivals": len(arr[r]), "mean": area[r] / len(arr[r]), "mean_lo": area_lo[r] / len(arr[r]), "mean_hi": area_hi[r] / len(arr[r]), "fifo_mean": sum(durs) / len(durs), "fifo_max": max(durs),
                "first": first, "second": second,
                "n_first": sum(series[i][r] for i in inwin[:h]) / max(1, h), "n_second": sum(series[i][r] for i in inwin[h:]) / max(1, len(inwin) - h),
            }
        )
    return {"per": per, "multi_down": multi_down, "down_blocks": down_blocks, "ticks": len(t), "last_t": t[-1]}


def rec_routes_names(rec):
    return [p["route"] for p in rec["per_route"]]


def fmt(x, nd=4):
    if x is None:
        return "NA"
    if isinstance(x, float):
        return f"{x:.{nd}f}"
    return str(x)


step_rows, route_rows, detail = [], [], []
all_status = set()
max_after = 0.0
timeouts_cut = 0

for sc in SCENARIOS:
    sdir = LAD / f"{sc}-{COUNT}"
    ladder = json.loads((sdir / "ladder.json").read_text())
    pscen = next(s for s in pred["scenarios"] if s["id"] == sc)
    stages = {round(st["rps_total"], 6): st for st in pscen["stages"]}
    assert ladder["scenario"] == sc and ladder["scale"] == COUNT and ladder["trial"] is False
    for ridx, rec in enumerate(ladder["rungs"]):
        rung = rec["rung"]
        rdir = next(sdir.glob(f"rung-{rung:02d}-*"))
        next_samples = None
        if ridx + 1 < len(ladder["rungs"]):
            nrung = ladder["rungs"][ridx + 1]["rung"]
            next_samples = read_samples(next(sdir.glob(f"rung-{nrung:02d}-*")) / "cpu.txt")
        assert rec["scenario"] == sc and rec["scheme"] == "B" and rec["rps_valid"] is True
        st = stages[round(rec["decided_rps"], 6)]
        run = json.loads((rdir / "run.json").read_text())
        plan = json.loads((rdir / "plan.json").read_text())
        summ = json.loads((rdir / "summary.json").read_text())
        samples = read_samples(rdir / "cpu.txt")
        win = rec["window_epoch"]
        window_s = rec["window_sec"]
        km = k6_measure(rdir / "k6-output.txt", plan, win, summ["scenario_start_ms"] / 1000.0)
        km_by_route = {pr["route"]: km["per"][i] for i, pr in enumerate(plan["routes"])}
        assert set(km_by_route) == set(rec_routes_names(rec))
        assert run["window_sec"] == window_s and rec["db_utilization"] == run["db_utilization"]["utilization"]
        assert int(run["db_utilization"]["cpus"]) == int(cores)
        for k in rec["http_status"]:
            all_status.add(k)

        # 1. utilisation: prediction (point) and measured, both as a share of the DB container's 2 cores
        pred_util = st["utilization"]["point"] * 100
        pred_util_up = st["utilization"]["upper"] * 100
        meas_util = rec["db_utilization_percent"]
        # the measured DB CPU seconds in the window (delta of the cgroup usage_usec over the kept window)
        meas_cpu_s = run["db_utilization"]["delta_usage_usec"] / 1e6

        # 2. what was sent, route by route, and the model's database work for it
        sent = {p["route"]: p["requests_sent"] for p in rec["per_route"]}
        tgt = {p["route"]: p["target_rps"] for p in rec["per_route"]}
        for r_name, v in tgt.items():  # the load plan carries the prediction's allocation
            assert abs(v - st["rps"][r_name]) < 1e-12, (sc, rung, r_name)
        assert set(sent) == set(st["rps"])
        assert sum(sent.values()) == rec["requests_sent_in_window"]
        total_rps = sum(st["rps"].values())
        work_total = 0.0
        wsum_time = 0.0
        wsum_meas = 0.0
        wsum_missing = []
        # the route with the longest requests (largest running-time area) must be the ZIP export: the one route the model gives a long time
        zip_name = max(km_by_route, key=lambda k: km_by_route[k]["area"])
        assert zip_name == "GET /books/{bookSlug}/export/zip", zip_name
        for _n, _m in km_by_route.items():
            assert _m["mean"] <= summ["duration_ms"]["max"] / 1000.0 + 1.5, (sc, rung, _n)  # an average cannot exceed the step's longest request
            assert _m["fifo_max"] <= summ["duration_ms"]["max"] / 1000.0 + 1.5, (sc, rung, _n)
        for r_name in sorted(sent):
            w1 = work(r_name)
            pw = sent[r_name] * w1
            work_total += pw
            weight = st["rps"][r_name] / total_rps
            if routes[r_name]["queries"] == 0 and routes[r_name]["rows_examined"] == 0:
                req_time, src, over = None, "NA", None  # the model gives this route no cost, so it gives no time either
                wsum_missing.append(r_name)
            else:
                req_time, src = bound(r_name), "upper_bound"
                over = req_time / window_s
                wsum_time += weight * req_time
            m = km_by_route[r_name]
            meas_t = m["mean"]
            wsum_meas += weight * meas_t
            route_rows.append(
                {
                    "count": COUNT,
                    "run": sc,
                    "step_rps": rec["decided_rps"],
                    "route": r_name,
                    "sent_requests": sent[r_name],
                    "pred_work_per_req_cpu_s": fmt(w1, 6),
                    "pred_work_cpu_s": fmt(pw, 4),
                    "req_time_s": fmt(req_time, 4),
                    "req_time_source": src,
                    "req_over_window": fmt(over, 4),
                    "weight": fmt(weight, 6),
                    "req_time_measured_s": fmt(meas_t, 3),
                    "req_time_measured_reading": "area_over_arrivals" if meas_t >= 1.0 else "below_tick_resolution",
                    "req_over_window_measured": fmt(meas_t / window_s, 4),
                    "running_area_s": fmt(m["area"], 1),
                    "arrivals_in_step": m["arrivals"],
                    "req_time_measured_lo_s": fmt(m["mean_lo"], 3),
                    "req_time_measured_hi_s": fmt(m["mean_hi"], 3),
                }
            )

        # 3. the end of the step: requests that finished after the window, and the longest wait after the window's end
        eos = rec["end_of_step"]
        after_n = eos["requests_finished_after_window"]
        after_s = eos["longest_after_window_ms"] / 1000.0
        assert after_n == summ["finished_after_window"] and summ["sent_after_window"] == 0
        max_after = max(max_after, after_s)
        assert (rdir / "k6.exit").read_text().strip() == "0"
        if summ["duration_ms"]["max"] / 1000.0 >= 1800.0:
            timeouts_cut += 1

        # the DB CPU after the window's end, from the sampler that kept running (it stops 'duration + 60 s' after it started)
        t_end, t_from = win["to"], win["from"]
        u_end = usage_at(samples, t_end)
        u_from = usage_at(samples, t_from)
        t_last, u_last = samples[-1]
        tail_s = t_last - t_end
        tail_cpu = (u_last - u_end) / 1e6
        # idle rate: the first sampling interval ends before the scenario's first request (checked), so nothing but the idle server ran in it
        (ta, ua), (tb, ub) = samples[0], samples[1]
        assert tb < summ["scenario_start_ms"] / 1000.0
        idle_rate = (ub - ua) / 1e6 / (tb - ta)  # cpu seconds per second (cores)
        tail_above_idle = tail_cpu - idle_rate * tail_s
        last_iv = (samples[-1][1] - samples[-2][1]) / 1e6 / (samples[-1][0] - samples[-2][0])
        interval_s = (samples[-1][0] - samples[0][0]) / (len(samples) - 1)
        covered = tail_s >= after_s + 1.5 * interval_s  # the sampler outlived the last request by more than one sampling interval
        gap_cpu = gap_s = None
        if next_samples is not None:
            # from this step's last sample to the first sample of the next step of the same scenario (taken before the next step's load starts)
            gap_s = next_samples[0][0] - t_last
            gap_cpu = (next_samples[0][1] - u_last) / 1e6
        if covered:
            basis = "a"
            spill = tail_above_idle
            spill_missing = ""
        elif next_samples is not None:
            basis = "a+b"
            spill = tail_cpu + gap_cpu - idle_rate * (tail_s + gap_s)
            spill_missing = ""
        else:
            basis = "a only"
            spill = None
            spill_missing = (
                f"the DB CPU sampler stopped {tail_s:.1f}s after the window end but the last request finished {after_s:.1f}s after it, "
                "and the step is the last of its scenario, so there is no next step whose first reading would give the DB CPU up to the end of the wait; "
                "the route of each request that ran past the window end is not recorded either"
            )

        # 4. one request's time over the window, weighted by the step's allocation (routes with a time only; see README)
        weighted = wsum_time / window_s
        weighted_meas = wsum_meas / window_s

        # 5. a sign of waiting inside the step: no per-request delays are recorded, so only the DB CPU rate in the two halves of the window
        mid = (t_from + t_end) / 2
        u_mid = usage_at(samples, mid)
        h1 = (u_mid - u_from) / 1e6 / ((mid - t_from) * cores) * 100
        h2 = (u_end - u_mid) / 1e6 / ((t_end - mid) * cores) * 100
        d = summ["duration_ms"]
        zm = km_by_route[zip_name]
        if len(zm["first"]) >= 1 and len(zm["second"]) >= 1:
            f_mean = sum(zm["first"]) / len(zm["first"])
            s_mean = sum(zm["second"]) / len(zm["second"])
            qratio = s_mean / f_mean
            qsign = "yes" if qratio >= QUEUE_RATIO_LINE else "no"
            qtext = (
                f"ZIP requests one by one (first in, first out from the running counts), by arrival in the window: first half mean {f_mean:.1f}s "
                f"over {len(zm['first'])}, second half mean {s_mean:.1f}s over {len(zm['second'])}, ratio {qratio:.2f} "
                f"(yes at {QUEUE_RATIO_LINE:.2f} or more: the builder's line, fixed before the numbers were read; the counts are given because a half with 1 or 2 requests is a weak reading); "
                f"ZIP running requests, mean of the ticks in the first / second half of the window {zm['n_first']:.2f} / {zm['n_second']:.2f}"
            )
        else:
            f_mean = s_mean = qratio = None
            qsign = "NA"
            qtext = f"too few ZIP requests arrived in a half of the window to compare (first half {len(zm['first'])}, second half {len(zm['second'])}; at least 1 each is needed)"
        evidence = (
            qtext + "; "
            f"DB CPU share of the window: first half {h1:.1f}%, second half {h2:.1f}%; "
            f"request duration (all routes, whole step) avg {d['avg'] / 1000:.1f}s p95 {d['p95'] / 1000:.1f}s max {d['max'] / 1000:.1f}s"
        )

        step_rows.append(
            {
                "count": COUNT,
                "run": sc,
                "step_rps": rec["decided_rps"],
                "window_s": window_s,
                "pred_util_pct": fmt(pred_util, 3),
                "meas_util_pct": fmt(meas_util, 3),
                "diff_pt": fmt(pred_util - meas_util, 3),
                "sent_requests": rec["requests_sent_in_window"],
                "pred_work_cpu_s": fmt(work_total, 3),
                "after_window_requests": after_n,
                "max_end_of_step_delay_s": fmt(after_s, 3),
                "spill_work_cpu_s": fmt(spill, 3),
                "spill_missing": spill_missing if spill_missing else "",
                "weighted_req_over_window": fmt(weighted, 4),
                "weighted_req_over_window_measured": fmt(weighted_meas, 4),
                "queue_sign": qsign,
                "queue_evidence": evidence,
            }
        )
        detail.append(
            {
                "run": sc,
                "rps": rec["decided_rps"],
                "window_s": window_s,
                "pred_util": pred_util,
                "pred_util_up": pred_util_up,
                "meas_util": meas_util,
                "meas_cpu_s": meas_cpu_s,
                "pred_steady_cpu_s": st["utilization"]["point"] * cores * window_s,
                "pred_sent_cpu_s": work_total,
                "after_n": after_n,
                "after_s": after_s,
                "tail_s": tail_s,
                "tail_cpu": tail_cpu,
                "idle_rate": idle_rate,
                "tail_above_idle": tail_above_idle,
                "basis": basis,
                "gap_s": gap_s,
                "gap_cpu": gap_cpu,
                "spill": spill,
                "last_iv": last_iv,
                "covered": covered,
                "h1": h1,
                "h2": h2,
                "weighted": weighted,
                "weighted_meas": weighted_meas,
                "zip_mean": zm["mean"],
                "zip_lo": zm["mean_lo"],
                "zip_hi": zm["mean_hi"],
                "zip_fifo_mean": zm["fifo_mean"],
                "zip_fifo_max": zm["fifo_max"],
                "zip_arrivals": zm["arrivals"],
                "qratio": qratio,
                "qsign": qsign,
                "q_counts": (len(zm["first"]), len(zm["second"])),
                "multi_down": km["multi_down"],
                "down_blocks": km["down_blocks"],
                "dmax": d["max"] / 1000,
                "davg": d["avg"] / 1000,
                "missing": wsum_missing,
                "warmup": plan["warmup_sec"],
            }
        )

# gaps between consecutive steps of one scenario (how long the lab was quiet between the end of one step and the start of the next)
gaps = []
from datetime import datetime

for sc in SCENARIOS:
    ladder = json.loads((LAD / f"{sc}-{COUNT}" / "ladder.json").read_text())
    rs = ladder["rungs"]
    for a, b in zip(rs, rs[1:]):
        gaps.append((sc, a["rung"], b["rung"], (datetime.fromisoformat(b["started_at"]) - datetime.fromisoformat(a["ended_at"])).total_seconds()))

WARMUP = sorted({x["warmup"] for x in detail})
assert len(WARMUP) == 1, WARMUP
checks.append(("39 valid steps read (s-3 from the second run only)", len(step_rows) == 39))
_md = [x for x in detail if x["multi_down"]]
checks.append(("progress lines: lines with two or more routes past their schedule at once exist in %d step(s), %d lines (bounds are given for them; see the README)" % (len(_md), sum(x["multi_down"] for x in detail)), True))
checks.append(("progress lines: in every line without a route past its schedule the route numbers add up to the total (assertion in the reader)", True))
checks.append(("progress lines: the arrivals of the seven routes add up to the load tool's final completed count in every step (assertion in the reader)", True))
checks.append(("progress lines: the route with the largest running-time area is the ZIP export in every step (assertion)", True))
checks.append(("progress lines: no route's mean time and no one-by-one time exceeds the step's longest request in summary.json (+1.5 s) (assertion)", True))
checks.append(("every step's HTTP status is 200 or 302 (no timeout, 5xx or status 0)", all_status <= {"200", "302"}))
checks.append(("no request lasted as long as the 1800 s request timeout", timeouts_cut == 0))
assert all(ok for _, ok in checks), checks

# --- write the CSVs -----------------------------------------------------------------------------------------------------
STEP_COLS = [
    "count", "run", "step_rps", "window_s", "pred_util_pct", "meas_util_pct", "diff_pt", "sent_requests", "pred_work_cpu_s",
    "after_window_requests", "max_end_of_step_delay_s", "spill_work_cpu_s", "spill_missing", "weighted_req_over_window",
    "weighted_req_over_window_measured", "queue_sign", "queue_evidence",
]
ROUTE_COLS = [
    "count", "run", "step_rps", "route", "sent_requests", "pred_work_per_req_cpu_s", "pred_work_cpu_s", "req_time_s",
    "req_time_source", "req_over_window", "weight",
    "req_time_measured_s", "req_time_measured_reading", "req_over_window_measured", "running_area_s", "arrivals_in_step",
    "req_time_measured_lo_s", "req_time_measured_hi_s",
]
for name, cols, rows in (("step_table.csv", STEP_COLS, step_rows), ("route_table.csv", ROUTE_COLS, route_rows)):
    with open(HERE / name, "w", newline="") as f:
        w = csv.DictWriter(f, fieldnames=cols, lineterminator="\n")
        w.writeheader()
        w.writerows(rows)

# --- the README -------------------------------------------------------------------------------------------------------------
lines = []
A = lines.append
A("# Step tables of B at 170,000 rows (fifth round)")
A("")
A("Tables made from the records only. Nothing was measured, no container was touched, and nothing here is a prediction, a test or a verdict: the committed prediction, the estimate, the calibration, the formula, the marking rule, the judging rule, the records and the pass/fail of this ladder are as they were. Every number is a value in a record, or an arithmetic on such values named below; where a value is not in the records it is `NA` and the file says what is missing.")
A("")
A("Made by `build_table.py` (`python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/170k/build_table.py`, from a checkout; paths are relative to the repository root). It writes `step_table.csv` (one row per step, 39 rows), `route_table.csv` (one row per route per step, 273 rows) and this file.")
A("")
A("## Conditions of the measurement (what B at 170,000 rows had)")
A("")
A("Read from `docs/research/lab-data/ladder-170k/README.md`, the `settings.json` of every step and `poc/lab/compose/lab.compose.yaml`. Differences in these conditions between counts are not mixed into a difference between counts in the tables.")
A("")
A("| Condition | Value at 170,000 rows (B) |")
A("|---|---|")
A("| Request timeout of the load tool | 1800 s, given to every request (`k6_request_timeout`) |")
A("| Web server timeout | nginx `fastcgi_read_timeout` 1800 s |")
A("| Wait at the end of a step | the load tool waits for the requests still running for as long as the request timeout, 1800 s (its graceful stop). It is a wait, not a cut: the longest wait after a window's end in any step is %.1f s, no request in any step lasted 1800 s, and every step's HTTP statuses are 200 or 302 only (the 302 is the route that redirects). So there was no cut at the end of a step. |" % max_after)
A("| PHP limits | `max_execution_time` 0 (off), `memory_limit` 4G, php-fpm `request_terminate_timeout` 0s, read from the php-fpm side before every step |")
A("| Database container | `cpus: 2` (a limit), `cpuset` 0-1; no memory limit is set on it. The prediction's `cores` is 2.0 |")
A("| App container | `cpus: 6`, memory limit 6 GiB; the memory/oom counters read 0 around every scenario except the stopped first run of s-3, which is not used (see below) |")
A("| Window | the kept window of a step is `window_sec` (1600 s at 0.025 rps down to 343 s at 0.7 rps), after a %d s warm-up inside the step's duration; the window length differs by step, and the requests sent in it run from 40 to 240 |" % WARMUP[0])
A("| Steps | 39 steps in 5 scenarios, all valid (`dropped 0`, rps within the tolerance); each scenario stopped by its own stop rule (DB utilisation at or above 80%) |")
A("| What could not be decided | nothing in these 39 steps. The first run of s-3 was stopped at its step 7 by `docker compose stop` for the maintainer's convenience, is **invalid as a whole** for the verdict, and is **not read here**: only the second run, `s-3-170000/`, is. `s-3-170000-INVALID-20261119/` is not used |")
A("")
A("The database server was restarted between the stop and the second run of s-3 (`ladder-170k/README.md`, \"The stop and the second run of s-3\"); s-1 and s-2 were measured before it and s-3 (second run) to s-5 after it. That is a condition that differs among the five scenarios; it is not an explanation of anything here.")
A("")
A("## The yardstick of utilisation")
A("")
A("- **Measured**: `db_utilization_percent` of the step's `record.json` = the growth of the database container's cgroup `usage_usec` (read from `cpu.stat` every ~5 s by the sampler, file `cpu.txt`) over the kept window, divided by (window seconds x 2 cores). The cores are the container's `cpus: 2` limit (`run.json`: `cpus` 2). It is the container's whole CPU time, so it includes the server's own background work and the CPU of any request that is running in the window, whichever step phase it was sent in.")
A("- **Predicted**: `stages[].utilization.point` of the scenario in `poc/lab/predictions/ladder-prediction-5-b170k.json` for the step's rps = (c_q x statements per second + c_row x rows per second) / cores, with `cores` 2.0 (`resources.cores`). `pred_util_pct` is the point reading; the upper reading is in the table below. Both are therefore a share of the same 2 cores.")
A("- `diff_pt` = `pred_util_pct` - `meas_util_pct` (percentage points; negative = the prediction is below the measurement).")
A("")
A("## How each column was made")
A("")
A("- `pred_work_per_req_cpu_s` = c_q x statements + c_row x rows read for one request of the route, with c_q = %.10g and c_row = %.10g (`calibration.coefficients` of the prediction) and the statements and rows of the route in the estimate it names (`estimate-170k/170k/estimate.json`, sha256 `%s`, checked equal to the prediction's `inputs.estimate_sha256`). For every scenario and step, the sum over routes of (the prediction's rps of the route x this value) / 2 reproduces the prediction's point utilisation (checked by the script at all 75 stages, see the checks below; the script does not take any number from this reproduction into a table other than the work per request)." % (cq, cr, est_sha))
A("- `pred_work_cpu_s` of a route = `sent_requests` x `pred_work_per_req_cpu_s`, with the requests actually sent inside the kept window (`per_route[].requests_sent`). The step's value is the sum over routes. It is the model's database CPU seconds for the requests that were sent; it is **not** the prediction's steady-state work for the window (`pred_util x 2 x window`), which is given beside it in the table below, and it is not the measured work.")
A("- `after_window_requests`, `max_end_of_step_delay_s`: `end_of_step.requests_finished_after_window` and `longest_after_window_ms` / 1000 of the record. The first is the number of requests sent in the window that had not finished at the window's end; the second is the largest (finish time - window end) among them.")
A("- `spill_work_cpu_s`: the database work that ran **after** the window's end for requests sent in the window. Not an estimate from the model: the route of each such request is not recorded, so no per-route estimate can be made. What the records hold is the DB CPU sampler (`cpu.txt`, the container's cgroup `usage_usec` about every 5 s), which keeps running 'duration + 60 s' after it started, i.e. about 55 s past the window's end. Two readings are used, both measured: (a) the usage at the sampler's last sample minus the usage at the window's end (linear between the two neighbouring samples); (b) for a step whose last request finished later than the sampler ran and that has a next step in the same scenario, the first reading of the next step's `cpu.txt` (taken before that step's load starts) minus this step's last usage, which covers the rest of the wait and the gap between the steps. The server's idle rate times the seconds covered is subtracted; the idle rate is the first sampling interval of the step (it ends before the scenario's first request; checked). Basis `a` = (a) alone, because the sampler outlived the last request by more than 1.5 sampling intervals; basis `a+b` = (a) + (b); `a only` = the last step of a scenario whose wait outlasted the sampler: no DB CPU after the sampler stop is recorded, the value is `NA` and `spill_missing` says so.")
A("- `req_time_s` (route table) = the model's php CPU time bound for one request of the route: 9.11 x the larger of the point and the upper reading of the database CPU time the model gives for one request (the CPU-time mark's bound, `AppLimitRisk`; the same value the prediction file records for the longest route, 540.86 s for the ZIP export, reproduced by the script and checked equal). `req_time_source` is `upper_bound` for every route that has one; this column is kept as it was and is **not** a measured time. A route the model gives no cost (the OIDC callback: 0 statements, 0 rows) has no bound and is `NA`. The bound is a php **CPU** time of a request that runs alone; it is not an elapsed time under load, and the ZIP route's measured one-request time (next bullet) is about a quarter of the bound.")
A("- **Measured one-request time per route** (added columns `req_time_measured_s`, `req_time_measured_reading`, `req_over_window_measured`, `running_area_s`, `arrivals_in_step`, `req_time_measured_lo_s`, `req_time_measured_hi_s` of `route_table.csv`; the `req_time_s` column and its `upper_bound` rows are unchanged). The summary of the load tool keeps avg/p95/max per step only, but its progress lines (`k6-output.txt`, about one line block per second) give, for each route's scenario (`r0`... = the routes of `plan.json` in order), the number of requests running. The area under that number over the whole step (the running requests x seconds, `running_area_s`, warm-up and the wait after the window included) divided by the number of requests the route started in the whole step (`arrivals_in_step`) is the mean elapsed time of one request of the route (Little's law): `req_time_measured_s`. The arrivals are the constant-arrival schedule of the plan (`start_offset_sec`, `rate` per `time_unit`, until `duration_sec`); the script checks that the arrivals of the seven routes add up to the load tool's final count of completed iterations in every step. **The load tool does not update a route's number after that route's schedule has ended** (the progress line shows a down arrow instead of a tick, and prints the last number again while the total in its header line keeps falling); in those lines the route's number is the total minus the other routes' numbers (a route shown as done or waiting, or not listed, has 0). This was needed in %d of the 39 steps (%d progress lines in all). **In %d lines of %d step(s) (%s) more than one route was in that state at once** (the ZIP route and two routes of short requests, which at that load were slow too; in the first such line their last numbers were 10, 4 and 3 running against 16 in the total, and in 58 of the 60 lines three routes were in that state, in 2 lines two); there the rest is shared in proportion to the routes' last numbers, and `req_time_measured_lo_s` / `req_time_measured_hi_s` give bounds that hold whatever the split (a route past its schedule can only have fewer running than its last printed number, so its upper bound is the smaller of that and the rest, and its lower bound is the rest minus the others' last numbers); in every other step the two are equal to `req_time_measured_s`. In the lines without a down arrow the route numbers add up to the total in every line (assertion). `req_time_measured_reading` is `below_tick_resolution` when the value is under 1 s: those routes' requests take milliseconds and a progress line sees one only by chance, so the value is not a measurement. A value of a few seconds or less (the short routes) rests on a handful of ticks and is a rough reading even when it is above 1 s (in s-1 at 0.3 rps and s-2 at 0.15 rps the short routes were slow, 10 to 25 s, and those are readings); the ZIP route is the one with a firm reading in every step. All routes stay in the table so that the weighted value can be summed. The ZIP route's measured time is %.1f to %.1f s over the 39 steps against the bound 540.86 s (a ratio of 1/%.1f to 1/%.1f); the script checks that no route's mean and no one-by-one time exceeds the step's longest request (`summary.json` max, +1.5 s for the tick)." % (sum(1 for x in detail if x["down_blocks"]), sum(x["down_blocks"] for x in detail), sum(x["multi_down"] for x in detail), len(_md), ", ".join("%s %s rps ZIP %.1f to %.1f s, reading used %.1f s" % (x["run"], x["rps"], x["zip_lo"], x["zip_hi"], x["zip_mean"]) for x in _md), min(x["zip_mean"] for x in detail), max(x["zip_mean"] for x in detail), max(PHP_CPU_OVER_DB * max(work(zip_route), work(zip_route, True)) / x["zip_mean"] for x in detail), min(PHP_CPU_OVER_DB * max(work(zip_route), work(zip_route, True)) / x["zip_mean"] for x in detail)))
A("- `req_over_window` = `req_time_s` / `window_s`. `weight` = the route's share of the step's requests in the prediction's allocation (rps of the route / sum of rps; the load plan carries exactly these rps, checked). `weighted_req_over_window` (step) = sum over routes with a bound of (weight x `req_time_s`) / `window_s`, the upper-bound reading; the OIDC callback is left out (`NA`), so it is a **lower** reading of the weighted value (the left-out route's time is not negative). `weighted_req_over_window_measured` (step) = sum over all seven routes sent of (weight x `req_time_measured_s`) / `window_s`, the measured reading (the OIDC callback has a measured value, below the tick resolution like the other short routes, so it is in the sum). Its weight differs by scenario (`weight` in `route_table.csv`: 1/7 in s-1, 0.05 in s-2 and s-5, 0.2 in s-3, 1/15 in s-4).")
A("- `queue_sign`: whether the requests waited longer later in the step, read from the ZIP route's running numbers (the only route whose requests are long enough to see). The ZIP requests are put one by one in first-in-first-out order: the k-th request to end is taken to be the k-th that arrived (arrival times from the plan; the number ended in a tick = arrivals so far - running now, the end placed in the middle of the tick), so each ZIP request has an elapsed time. The ZIP requests that arrived in the first half of the kept window are compared with those that arrived in the second half: `yes` if the second half's mean is %.2f times the first half's or more, `no` if less, `NA` if a half has no ZIP request. **The line %.2f is the builder's line, fixed before the numbers were read; it is not a rule of the test.** The order of ending is not always the order of arriving when requests overlap, so a single request's time is approximate; the means of a half are less so. A half with 1 or 2 requests is a weak reading (the counts are in `queue_evidence`); the minimum of requests per half was first 2 and was lowered to 1 after the first run showed 11 steps with 1 request in a half, and no step's sign changes with it (every step read at 1 request per half has a ratio between %.2f and %.2f). `queue_evidence` gives the means, the counts, the ratio, the mean number of ZIP requests running in the two halves, the DB CPU share in the two halves and the avg/p95/max of the step." % (QUEUE_RATIO_LINE, QUEUE_RATIO_LINE, min(x["qratio"] for x in detail if x["qratio"] is not None and min(x["q_counts"]) == 1), max(x["qratio"] for x in detail if x["qratio"] is not None and min(x["q_counts"]) == 1)))
A("")
A("## Reverse spill (the previous step's work running into the next step's window)")
A("")
A("- At the end of a step the load tool waits for **every** request to finish (graceful stop 1800 s; no request lasted that long), and the next step starts only after the load tool, the two samplers and the write guard have ended. The gap between the end of one step and the start of the next, over the %d consecutive pairs of steps, is %.0f s at the least and %.0f s at the most (from `ended_at.txt` and `started_at.txt`)." % (len(gaps), min(g[3] for g in gaps), max(g[3] for g in gaps)))
A("- The first sampling interval of every step, which ends before the scenario's first request, is the server's idle rate. Its largest value over the 39 steps is %.4f cores (the smallest %.4f), against the in-window rates in the table: no step began with the database still working on the previous step's requests." % (max(x["idle_rate"] for x in detail), min(x["idle_rate"] for x in detail)))
A("- Inside a step the warm-up (%d s before the window) sends requests whose work can run into the window; that is part of the measured utilisation by construction and cannot be separated here." % WARMUP[0])
A("")
A("## Step table (excerpt of the columns that need a number beside them)")
A("")
A("Utilisation in % of 2 cores; CPU in cpu-seconds. `pred (steady)` = the prediction's point utilisation x 2 cores x window; `pred (sent)` = the model's work for the requests that were sent (`pred_work_cpu_s`); `measured` = the growth of the DB cgroup CPU over the window; `tail a` = DB CPU in the seconds after the window that the sampler covers, above the idle rate (with the seconds covered); `spill` = `spill_work_cpu_s` with its basis (`a`, `a+b`, or `NA` for `a only`, where `tail a` is a partial reading because the sampler stopped before the last request finished).")
A("")
A("| run | rps | window s | pred % (point / upper) | meas % | diff pt | pred (steady) cpu-s | pred (sent) cpu-s | measured cpu-s | sent | after window | longest after s | tail a cpu-s (covered s) | spill cpu-s (basis) | longest request s (step max) | DB share 1st / 2nd half % | weighted req / window (upper) |")
A("|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|")
for x, row in zip(detail, step_rows):
    A(
        "| %s | %s | %d | %.2f / %.2f | %.2f | %+.2f | %.1f | %.1f | %.1f | %s | %d | %.1f | %.2f (%.1f) | %s | %.1f | %.1f / %.1f | %.4f |"
        % (
            x["run"], x["rps"], x["window_s"], x["pred_util"], x["pred_util_up"], x["meas_util"], x["pred_util"] - x["meas_util"],
            x["pred_steady_cpu_s"], x["pred_sent_cpu_s"], x["meas_cpu_s"], row["sent_requests"], x["after_n"], x["after_s"],
            x["tail_above_idle"], x["tail_s"], ("NA" if x["spill"] is None else "%.2f" % x["spill"]) + " (" + x["basis"] + ")", x["dmax"], x["h1"], x["h2"], x["weighted"],
        )
    )
A("")
A("")
A("## Measured one-request time of the ZIP route, and the queue sign")
A("")
A("`zip bound` = `req_time_s` of the ZIP route (540.9 s, the model's php CPU bound, not a time under load); `zip measured` = running-time area / arrivals of the ZIP route (`req_time_measured_s`); `zip fifo` = the mean and the largest of the one-by-one times (first in, first out); `measured / window` = `zip measured` / `window_s`; `weighted req / window` = the upper-bound reading beside the measured reading (`weighted_req_over_window`, `weighted_req_over_window_measured`); `ratio` = second half / first half of the one-by-one ZIP times by arrival in the window (the counts in the evidence), `sign` = `queue_sign` with the line fixed at %.2f." % QUEUE_RATIO_LINE)
A("")
A("| run | rps | window s | zip arrivals | zip measured s | zip fifo mean / max s | zip bound s | bound / measured | measured / window | weighted req / window (upper / measured) | ratio | sign |")
A("|---|---|---|---|---|---|---|---|---|---|---|---|")
for x in detail:
    A(
        "| %s | %s | %d | %d | %.1f | %.1f / %.1f | %.1f | %.2f | %.3f | %.4f / %.4f | %s | %s |"
        % (
            x["run"], x["rps"], x["window_s"], x["zip_arrivals"], x["zip_mean"], x["zip_fifo_mean"], x["zip_fifo_max"], bound(zip_route),
            bound(zip_route) / x["zip_mean"], x["zip_mean"] / x["window_s"], x["weighted"], x["weighted_meas"],
            "NA" if x["qratio"] is None else "%.2f" % x["qratio"], x["qsign"],
        )
    )
A("")
A("Reading notes (arithmetic on the table, not a result and not a test):")
A("")
A("- `pred (sent)` differs from `pred (steady)` only by the plan's whole numbers of requests per route (for example 6 requests of the ZIP route in a window where the rate gives 5.71); it is the sent counts, not a different model.")
_zero = [x["tail_above_idle"] for x in detail if x["after_n"] == 0]
A("- Resolution of `spill`: in the %d steps in which no request finished after the window, the tail above the idle rate is between %.2f and %.2f cpu-s; differences of that size are the noise of the idle rate, not work." % (len(_zero), min(_zero), max(_zero)))
A("- Of the routes that were sent, the ZIP route is the only one whose model work for one request is more than 0.01 cpu-s. The step's longest request is between %.1f and %.1f s over the 39 steps (the ZIP route's, by the running numbers), and the ZIP route's php CPU bound is 540.86 s." % (min(x["dmax"] for x in detail), max(x["dmax"] for x in detail)))
A("")
A("## Route table (what one request of each route is, at 170,000 rows)")
A("")
A("The model's values for one request; the sent counts and weights per step are in `route_table.csv`.")
A("")
A("| route | statements | rows read | database CPU for one request, point (s) | php CPU bound for one request (s) | note |")
A("|---|---|---|---|---|---|")
for r_name in sorted(routes):
    r = routes[r_name]
    note = []
    if r_name == "GET /books/{slug}":
        note.append("taken out of the mix by the prediction's allocation (placeholder limit); never sent in this ladder")
    if r["queries"] == 0 and r["rows_examined"] == 0:
        note.append("no cost in the model: no bound")
    A(
        "| `%s` | %s | %s | %s | %s | %s |"
        % (
            r_name, f"{r['queries']:.2f}", f"{r['rows_examined']:.2f}", f"{work(r_name):.6f}",
            "NA" if (r["queries"] == 0 and r["rows_examined"] == 0) else f"{bound(r_name):.3f}", "; ".join(note),
        )
    )
A("")
A("## Checks run by the script")
A("")
for text, ok in checks:
    A("- %s: %s" % (text, "yes" if ok else "NO"))
A("- the load plan's rps of every route in every step equals the prediction's allocated rps (assertion; the script stops otherwise): yes")
A("- the first sampling interval of every step ends before the scenario's first request (assertion): yes")
A("")
A("## Files")
A("")
A("- `build_table.py`: reads the records and the prediction and writes the three files below (no network, no container, no write outside this directory).")
A("- `step_table.csv`: columns `count, run, step_rps, window_s, pred_util_pct, meas_util_pct, diff_pt, sent_requests, pred_work_cpu_s, after_window_requests, max_end_of_step_delay_s, spill_work_cpu_s, spill_missing, weighted_req_over_window, weighted_req_over_window_measured, queue_sign, queue_evidence`.")
A("- `route_table.csv`: columns `count, run, step_rps, route, sent_requests, pred_work_per_req_cpu_s, pred_work_cpu_s, req_time_s, req_time_source, req_over_window, weight, req_time_measured_s, req_time_measured_reading, req_over_window_measured, running_area_s, arrivals_in_step, req_time_measured_lo_s, req_time_measured_hi_s` (the first eleven are as before; the last seven are added, `req_time_s` and its `upper_bound` rows are kept).")
(HERE / "README.md").write_text("\n".join(lines) + "\n")

print("steps", len(step_rows), "route rows", len(route_rows))
for text, ok in checks:
    print("check:", text, ok)
print("max after-window wait s", max_after)
print("gaps min/max", min(g[3] for g in gaps), max(g[3] for g in gaps))
