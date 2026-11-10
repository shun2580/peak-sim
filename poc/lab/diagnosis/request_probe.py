#!/usr/bin/env python3
"""One request at a time against the lab, for the diagnosis of the backtest's miss. Not the load tool: nothing here sends
more than one request at once, and nothing here changes the lab (it reads, and it sends GET requests).

Two parts, kept apart so they never overlap in time:

  http     per route: one warm-up request, then N requests (default 3), one at a time, through the web server and php-fpm,
           the way the load reaches the app. Around each request: the DB container's `cpu.stat` (usage_usec) and the
           server's global counters (Handler_read_*, Questions, ...). A "null" sequence (the same two reads with no
           request between them) is repeated first, and an idle reading (a few seconds of no request) too; the null's
           median is taken off every window, and the idle rate times the window is taken off as well.
  inproc   per route: N times a fresh `measure-one` process (poc/runtime/bin/measure-one, the measurement's own way:
           a warm-up request, then the cold one that the estimate is compared with) inside the tools container, which
           gives the number of statements and the rows the database looked at (the Handler_read_* of the session,
           D96/D101/D102) of one request.

  report   puts the records beside the estimate (point and safe side) and the calibration's coefficients and works out
           `estimate / measured` for the rows, the statements and the DB CPU time.

Usage:
  request_probe.py run    --scale=N --out=DIR [--routes=1,2] [--reps=3] [--part=both|http|inproc] [--timeout=180] [--label=text]
  request_probe.py report --scale=N --dir=DIR --estimate=FILE --prediction=FILE [--out=DIR]
  request_probe.py compare --cut=DIR --uncut=DIR --route-contains=TEXT --out=DIR   two records of one route side by side
  request_probe.py counts --scale=N --out=DIR     the row counts of the three N tables (entities, images, references)

"Other requests" (the web server's request counter between the two probes, less the probes themselves) is recorded for every
request and is not checked by the run: it does not stop. The record's own column shows whether it was 0 on each request.
After a request that did not come back quickly and in full, one SHOW FULL PROCESSLIST is taken after the window has closed, as the
user the probe already uses (it adds no grant and no other user); where it cannot be read, the record says so.

Exit codes: 0 done / 2 usage / 3 the lab is not in the state the run needs (wrong scale, no stack, targets for another scale).
"""
from __future__ import annotations

import argparse
import json
import os
import re
import statistics
import subprocess
import sys
import time
from datetime import datetime
from typing import Any

DB = "peak-lab-db"
APP = "peak-lab-app"
WEB = "peak-lab-web"
K6 = "peak-lab-k6"
TOOLS = "peak-lab-tools"

HANDLERS = [
    "Handler_read_first", "Handler_read_key", "Handler_read_last", "Handler_read_next",
    "Handler_read_prev", "Handler_read_rnd", "Handler_read_rnd_next",
]
COUNTERS = HANDLERS + ["Questions", "Com_select", "Com_begin", "Com_rollback", "Com_commit", "Com_set_option"]

STATUS_SQL = "SHOW GLOBAL STATUS WHERE Variable_name IN (" + ",".join("'%s'" % c for c in COUNTERS) + ")"
# Before the window the counters come first and cpu.stat last; after it cpu.stat comes first and the counters last, so that the
# client's own work falls outside the window (what is left of it is what the null sequence measures).
BEFORE_CMD = 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B -e "%s"; cat /sys/fs/cgroup/cpu.stat' % STATUS_SQL
AFTER_CMD = 'cat /sys/fs/cgroup/cpu.stat; MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B -e "%s"' % STATUS_SQL

PROBE_JS = """import http from 'k6/http';
export const options = { vus: 1, iterations: 1, discardResponseBodies: false };
export default function () {
  const r = http.get(__ENV.PROBE_URL, { headers: JSON.parse(__ENV.PROBE_HEADERS || '{}'), redirects: 0, timeout: __ENV.PROBE_TIMEOUT || '180s' });
  const size = r.body ? (typeof r.body === 'string' ? r.body.length : r.body.byteLength) : 0;
  console.log('PROBE ' + JSON.stringify({ status: r.status, duration_ms: r.timings.duration, waiting_ms: r.timings.waiting, body_bytes: size, error: r.error || null, error_code: r.error_code || 0 }));
}
"""

ACT_AS_DEFAULT = "BookStack\\Users\\Models\\User:1:web"


# ---------------------------------------------------------------- pure parts (tested in test_request_probe.py)

def parse_cpu_stat(text: str) -> int:
    m = re.search(r"^usage_usec\s+(\d+)\s*$", text, re.M)
    if m is None:
        raise ValueError("no usage_usec line in cpu.stat")
    return int(m.group(1))


def parse_global_status(text: str) -> dict[str, int]:
    out: dict[str, int] = {}
    for line in text.splitlines():
        parts = line.split("\t")
        if len(parts) == 2 and parts[0] in COUNTERS and parts[1].isdigit():
            out[parts[0]] = int(parts[1])
    return out


def parse_snapshot(text: str) -> dict[str, Any]:
    """The output of BEFORE_CMD or AFTER_CMD: {'usage_usec': int, 'counters': {...}}."""
    return {"usage_usec": parse_cpu_stat(text), "counters": parse_global_status(text)}


def handler_total(counters: dict[str, int]) -> int:
    return sum(counters.get(h, 0) for h in HANDLERS)


def delta(before: dict[str, Any], after: dict[str, Any]) -> dict[str, Any]:
    keys = sorted(set(before["counters"]) & set(after["counters"]))
    return {
        "cpu_usec": after["usage_usec"] - before["usage_usec"],
        "counters": {k: after["counters"][k] - before["counters"][k] for k in keys},
    }


def parse_nginx_requests(text: str) -> int:
    lines = text.strip().splitlines()
    for i, line in enumerate(lines):
        if line.strip().startswith("server accepts handled requests"):
            return int(lines[i + 1].split()[2])
    raise ValueError("no request counter in the web server's status")


def median_of(values: list[float]) -> float:
    return float(statistics.median(values)) if values else 0.0


def null_profile(nulls: list[dict[str, Any]]) -> dict[str, Any]:
    """The median of what an empty window reads: cpu_usec, wall_s, every counter, and the handler total."""
    keys = sorted(set.intersection(*[set(n["counters"]) for n in nulls])) if nulls else []
    return {
        "samples": len(nulls),
        "cpu_usec": median_of([n["cpu_usec"] for n in nulls]),
        "wall_s": median_of([n["wall_s"] for n in nulls]),
        "counters": {k: median_of([n["counters"][k] for n in nulls]) for k in keys},
        "cpu_usec_all": [n["cpu_usec"] for n in nulls],
    }


def idle_rate(idle: dict[str, Any], null: dict[str, Any]) -> float:
    """usec of DB CPU per second with no request: the idle window's delta less the null's, over the time between them."""
    span = idle["wall_s"] - null["wall_s"]
    if span <= 0:
        return 0.0
    return max(0.0, (idle["cpu_usec"] - null["cpu_usec"]) / span)


def net_window(raw: dict[str, Any], null: dict[str, Any], rate: float) -> dict[str, Any]:
    """A request window with the empty window and the idle drift taken off."""
    extra_wall = max(0.0, raw["wall_s"] - null["wall_s"])
    cpu_net = raw["cpu_usec"] - null["cpu_usec"] - rate * extra_wall
    counters = {k: raw["counters"][k] - null["counters"].get(k, 0.0) for k in raw["counters"]}
    return {
        "cpu_net_usec": cpu_net,
        "cpu_minus_null_usec": raw["cpu_usec"] - null["cpu_usec"],
        "idle_drift_usec": rate * extra_wall,
        "handlers_net": sum(counters.get(h, 0.0) for h in HANDLERS),
        "questions_net": counters.get("Questions", 0.0),
        "counters_net": counters,
    }


def below_floor(cpu_net_usec: float, null: dict[str, Any], idle_drift_usec: float = 0.0) -> bool:
    """The reading is no larger than what the empty windows themselves scatter by, or than the idle drift that was taken off it
    (the drift is a rate worked out over a different stretch of time, so it cannot be trusted to better than its own size):
    a ratio on it would be noise."""
    spread = max(null["cpu_usec_all"]) - min(null["cpu_usec_all"]) if null.get("cpu_usec_all") else 0.0
    return cpu_net_usec <= 3.0 * spread or cpu_net_usec <= idle_drift_usec


def scatter_exceeds_value(values: list[float]) -> bool:
    """The three readings of one route scatter by more than their own median: the median says nothing the spread does not
    cover, so a ratio on it would be noise. (Added in the diagnosis, after the 100,000 reading, to name a case the null-window rule
    did not catch; it only withholds a ratio, it changes no number.)"""
    if len(values) < 2:
        return False
    return (max(values) - min(values)) > abs(median_of(values))


def ratio(estimate: float | None, measured: float | None) -> float | None:
    if estimate is None or measured is None or measured == 0:
        return None
    return estimate / measured


def per_request_cpu(c_q: float, c_row: float, queries: float, rows: float) -> float:
    """Seconds of DB CPU one request costs in the prediction (spec section 5: L = lambda * (c_q * q + c_row * rows))."""
    return c_q * queries + c_row * rows


def estimate_routes(estimate: dict[str, Any]) -> dict[str, dict[str, float]]:
    """route -> point queries, point rows, safe-side rows (the upper side of a marked route, else the point)."""
    out: dict[str, dict[str, float]] = {}
    for r in estimate["routes"]:
        rows = float(r["rows_examined"])
        upper = r.get("rows_examined_upper")
        out[r["route"]] = {
            "queries": float(r["queries"]),
            "rows": rows,
            "rows_safe": float(upper) if upper is not None else rows,
            "marked": bool(r.get("marked")),
        }
    return out


def parse_probe_line(text: str) -> dict[str, Any] | None:
    for line in text.splitlines():
        m = re.search(r"PROBE (\{.*\})", line)
        if m:
            body = m.group(1)
            if '\\"' in body:  # k6 writes the message as a quoted log field
                body = body.replace('\\"', '"')
            return json.loads(body)
    return None


N_TABLES = ["entities", "images", "references"]


def parse_process_list(text: str) -> list[dict[str, Any]]:
    """The tab-separated rows of SHOW FULL PROCESSLIST (Id, User, Host, db, Command, Time, State, Info), the probe's own
    statement left out."""
    rows = []
    for line in text.splitlines():
        parts = line.split("\t")
        if len(parts) < 8:
            continue
        info = parts[7]
        if "PROCESSLIST" in info.upper():
            continue
        rows.append({"id": parts[0], "user": parts[1], "db": parts[3], "command": parts[4], "time_s": parts[5], "state": parts[6], "info": info[:200]})
    return rows


def parse_table_counts(text: str) -> dict[str, int]:
    out: dict[str, int] = {}
    for line in text.splitlines():
        parts = line.split("\t")
        if len(parts) == 2 and parts[0] in N_TABLES and parts[1].isdigit():
            out[parts[0]] = int(parts[1])
    return out


def parse_attempts(text: str) -> list[dict[str, Any]]:
    out = []
    for line in text.splitlines():
        line = line.strip()
        if line.startswith('{"route"'):
            out.append(json.loads(line))
    return out


# ---------------------------------------------------------------- the lab (docker)

def sh(cmd: list[str], timeout: float | None = None, stdin: str | None = None) -> subprocess.CompletedProcess:
    # errors="replace": a route's output may hold bytes that are not UTF-8 (a ZIP's), which must not stop the run.
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, input=stdin, encoding="utf-8", errors="replace")


def snapshot(which: str) -> tuple[dict[str, Any], float]:
    cmd = BEFORE_CMD if which == "before" else AFTER_CMD
    r = sh(["docker", "exec", DB, "sh", "-c", cmd], timeout=60)
    if r.returncode != 0:
        raise RuntimeError("cannot read the database's counters: " + r.stderr.strip()[:200])
    return parse_snapshot(r.stdout), time.time()


def app_usage() -> int:
    r = sh(["docker", "exec", APP, "cat", "/sys/fs/cgroup/cpu.stat"], timeout=30)
    if r.returncode != 0:
        raise RuntimeError("cannot read the app container's cpu.stat")
    return parse_cpu_stat(r.stdout)


# Reading cpu.stat with `docker exec` runs a process inside the container's cgroup, so every poll adds CPU time to the reading
# itself (measured: about 38 ms per poll on the app container, about 19 ms on the DB's). Polling the DB container while a window is
# open would put that into the window, so the wait for the end of the PHP work polls the APP container only (the DB's own
# statements end with the request: the PHP work is synchronous, and a cut request closes its connection). A quiet interval is one
# that grew by less than QUIET_USEC: above one poll's overhead, far below one busy worker.
QUIET_USEC = 80000
QUIET_POLLS = 3
POLL_INTERVAL_S = 0.5
FAST_ANSWER_S = 5.0


def settled(history: list[int], quiet_usec: int = QUIET_USEC, polls: int = QUIET_POLLS) -> bool:
    """True when the CPU time grew by less than quiet_usec in each of the last `polls` intervals."""
    if len(history) < polls + 1:
        return False
    tail = history[-(polls + 1):]
    return all(tail[i + 1] - tail[i] < quiet_usec for i in range(polls))


def settle(max_s: int = 900) -> dict[str, Any]:
    """Waits until the PHP work is over: the app container's CPU time stops growing for QUIET_POLLS polls in a row, whatever
    the client saw. The client's answer (or its timeout) is not the end of the PHP work: nginx or the client may leave first."""
    history = [app_usage()]
    t0 = time.time()
    while time.time() - t0 < max_s:
        time.sleep(POLL_INTERVAL_S)
        history.append(app_usage())
        if settled(history):
            return {"waited_s": time.time() - t0, "app_usage_usec": history[-1], "polls": len(history), "gave_up": False,
                    "quiet_growth_usec": median_of([history[i + 1] - history[i] for i in range(len(history) - QUIET_POLLS - 1, len(history) - 1)])}
    return {"waited_s": time.time() - t0, "app_usage_usec": history[-1], "polls": len(history), "gave_up": True, "quiet_growth_usec": 0.0}


def app_poll_overhead() -> float:
    """What one poll of the app container's cpu.stat adds to the reading, with nothing running (median of 6 intervals)."""
    h = [app_usage()]
    for _ in range(6):
        time.sleep(POLL_INTERVAL_S)
        h.append(app_usage())
    return median_of([h[i + 1] - h[i] for i in range(6)])


def process_list() -> dict[str, Any]:
    """One SHOW FULL PROCESSLIST as the user the probe already uses. {'readable': bool, 'rows': [...], 'error': str}."""
    r = sh(["docker", "exec", DB, "sh", "-c", 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B -e "SHOW FULL PROCESSLIST"'], timeout=60)
    if r.returncode != 0:
        return {"readable": False, "rows": [], "error": r.stderr.strip()[:200]}
    return {"readable": True, "rows": parse_process_list(r.stdout), "error": ""}


def table_counts(database: str) -> dict[str, int]:
    # the SQL goes in on stdin: `references` is a reserved word and needs its backticks, which a shell would take for a command
    sql = " UNION ALL ".join("SELECT '%s', COUNT(*) FROM `%s`.`%s`" % (t, database, t) for t in N_TABLES)
    r = sh(["docker", "exec", "-i", DB, "sh", "-c", 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B'], timeout=120, stdin=sql)
    if r.returncode != 0:
        raise RuntimeError("cannot count the N tables: " + r.stderr.strip()[:200])
    return parse_table_counts(r.stdout)


def nginx_requests() -> int:
    r = sh(["docker", "exec", K6, "wget", "-qO-", "http://lab-web:8081/__lab/status"], timeout=30)
    if r.returncode != 0:
        raise RuntimeError("cannot read the web server's counter")
    return parse_nginx_requests(r.stdout)


def window(action) -> dict[str, Any]:
    """before-snapshot, action(), after-snapshot -> the window's raw readings."""
    b, t0 = snapshot("before")
    result = action()
    a, t1 = snapshot("after")
    d = delta(b, a)
    return {"cpu_usec": d["cpu_usec"], "counters": d["counters"], "wall_s": t1 - t0, "result": result}


def current_scale() -> int:
    r = sh(["docker", "exec", APP, "printenv", "PEAK_LAB_SCALE"], timeout=30)
    if r.returncode != 0:
        raise RuntimeError("the app container is not running")
    return int(r.stdout.strip())


def load_targets(scale: int, out_dir: str) -> dict[str, Any]:
    path = os.path.join(out_dir, "targets-%d.json" % scale)
    with open(path) as fh:
        return json.load(fh)


def route_list(root: str) -> list[str]:
    with open(os.path.join(root, "poc/lab/routes/route-list.json")) as fh:
        return [r["route"] for r in json.load(fh)["selected"]]


def one_request(url: str, headers: dict[str, str], timeout: int) -> dict[str, Any]:
    t0 = time.time()
    r = sh(["docker", "exec", "-e", "PROBE_URL=" + url, "-e", "PROBE_HEADERS=" + json.dumps(headers),
            "-e", "PROBE_TIMEOUT=%ds" % timeout, K6, "k6", "run", "--quiet", "/out/probe_one.js"], timeout=timeout + 120)
    wall = time.time() - t0
    parsed = parse_probe_line(r.stdout + "\n" + r.stderr)
    if parsed is None:
        raise RuntimeError("k6 gave no result line: " + (r.stderr or r.stdout)[-300:])
    parsed["k6_exec_wall_s"] = wall
    return parsed


def http_part(args, root: str, routes: list[str], targets: dict[str, Any], log) -> None:
    out_file = os.path.join(args.out, "http.jsonl")
    with open(out_file, "a") as out:
        def emit(row: dict[str, Any]) -> None:
            out.write(json.dumps(row, sort_keys=True) + "\n")
            out.flush()

        nulls = []
        for _ in range(5):
            w = window(lambda: None)
            nulls.append({"cpu_usec": w["cpu_usec"], "wall_s": w["wall_s"], "counters": w["counters"]})
        null = null_profile(nulls)
        emit({"kind": "null", "profile": null})
        b, t0 = snapshot("before")
        time.sleep(10)
        a, t1 = snapshot("after")
        d = delta(b, a)
        idle = {"cpu_usec": d["cpu_usec"], "wall_s": t1 - t0}
        rate = idle_rate(idle, null)
        emit({"kind": "idle", "raw": idle, "rate_usec_per_s": rate})
        app_overhead = app_poll_overhead()
        emit({"kind": "app_poll_overhead", "usec_per_poll": app_overhead})
        log("null cpu median %.0f usec, idle rate %.0f usec/s, one poll of the app's cpu.stat %.0f usec" % (null["cpu_usec"], rate, app_overhead))

        for route in routes:
            t = targets["routes"][route]
            url = targets["base_url"] + t["path"]
            headers = dict(targets.get("headers") or {}) | dict(t.get("headers") or {})
            for rep in range(0, args.reps + 1):
                n0 = nginx_requests()
                app0 = app_usage()
                tstart = time.time()

                def act() -> dict[str, Any]:
                    res_ = one_request(url, headers, args.timeout)
                    res_["answered_after_s"] = time.time() - tstart
                    # A request that was answered in full, quickly, is over (php-fpm sends the last byte at the end of the script);
                    # waiting on it would only put the database's idle drift into a small window. Any other answer (an error, a
                    # slow one, no answer) may leave PHP running, so it waits for the app container to go quiet.
                    ok = res_["error"] is None and 200 <= res_["status"] < 400 and res_["answered_after_s"] < FAST_ANSWER_S
                    res_["settle"] = ({"waited_s": 0.0, "app_usage_usec": app_usage(), "polls": 1, "gave_up": False, "skipped": True, "quiet_growth_usec": 0.0}
                                      if ok else settle())
                    return res_

                raw = window(act)
                n1 = nginx_requests()
                res = raw.pop("result")
                quick = res["error"] is None and 200 <= res["status"] < 400 and res["answered_after_s"] < FAST_ANSWER_S
                plist = None if quick else process_list()
                res["php_cpu_usec_raw"] = res["settle"]["app_usage_usec"] - app0
                res["php_cpu_usec"] = res["php_cpu_usec_raw"] - res["settle"]["polls"] * app_overhead
                row = {
                    "kind": "request", "route": route, "path": t["path"], "rep": rep, "phase": "warmup" if rep == 0 else "cold",
                    "scale": args.scale, "result": res, "raw": raw,
                    "nginx_requests_between_probes": n1 - n0,
                    "processlist_after_window": plist,
                    "net": None if rep == 0 else net_window(raw, null, rate),
                    "started": datetime.now().astimezone().isoformat(timespec="seconds"),
                }
                emit(row)
                log("%s rep %d: status %s, %.0f ms, php finished %.1f s after the answer, db cpu raw %d usec, php cpu %d usec, other requests seen: %d" % (
                    route, rep, res["status"], res["duration_ms"], res["settle"]["waited_s"], raw["cpu_usec"], res["php_cpu_usec"], (n1 - n0) - 2))


def inproc_part(args, root: str, routes: list[str], targets: dict[str, Any], log) -> None:
    out_file = os.path.join(args.out, "inproc.jsonl")
    with open(out_file, "a") as out:
        for route in routes:
            t = targets["routes"][route]
            guest = (t.get("headers") or {}).get("X-Lab-Login") == "none"
            for rep in range(1, args.reps + 1):
                cmd = ["docker", "exec", "-e", "PEAK_MEASURE_PROFILE=bookstack", "-e", "PEAK_MEASURE_REQUEST_TIMEOUT=600", TOOLS,
                       "php", "/peak-sim/poc/runtime/bin/measure-one", "--scale=%d" % args.scale, "--uri=" + t["path"],
                       "--route=" + route, "--html", "--phases=warmup,cold"]
                if not guest:
                    cmd.append("--act-as=" + ACT_AS_DEFAULT)
                t0 = time.time()
                r = sh(cmd, timeout=1500)
                wall = time.time() - t0
                for a in parse_attempts(r.stdout):
                    rows_read = a.get("rows_read") or {}
                    out.write(json.dumps({
                        "kind": "attempt", "route": route, "rep": rep, "attempt": a["attempt"],
                        "status": a["status"], "queries": a["queries"], "rows_examined": a.get("rows_examined"),
                        "rows_returned": sum(rows_read.values()) if rows_read else 0, "bytes": a.get("bytes"),
                        "class": a.get("class"), "detail": a.get("detail"), "process_wall_s": wall,
                        "baseline": a.get("rows_examined_baseline"),
                    }, sort_keys=True) + "\n")
                out.flush()
                log("%s rep %d: %d attempts, exit %d, %.1f s" % (route, rep, len(parse_attempts(r.stdout)), r.returncode, wall))


def run(args) -> int:
    root = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", ".."))
    lab_out = os.environ.get("PEAK_LAB_OUT_DIR", os.path.expanduser("~/.cache/peak-lab/out"))
    log = lambda s: print(datetime.now().strftime("%H:%M:%S ") + s, flush=True)
    try:
        scale = current_scale()
    except Exception as e:  # noqa: BLE001
        print("the lab is not up: %s" % e, file=sys.stderr)
        return 3
    if scale != args.scale:
        print("the app reads scale %d, not %d: run poc/lab/bin/use-db %d first" % (scale, args.scale, args.scale), file=sys.stderr)
        return 3
    os.makedirs(args.out, exist_ok=True)
    targets = load_targets(args.scale, lab_out)
    if targets.get("scale") != args.scale:
        print("targets file is for another scale", file=sys.stderr)
        return 3
    all_routes = route_list(root)
    picked = all_routes if args.routes in (None, "all") else [all_routes[int(i) - 1] for i in args.routes.split(",")]
    with open(os.path.join(lab_out, "probe_one.js"), "w") as fh:
        fh.write(PROBE_JS)
    with open(os.path.join(args.out, "context.json"), "w") as fh:
        json.dump({"scale": args.scale, "database": targets.get("database"), "label": args.label, "routes": picked,
                   "reps": args.reps, "part": args.part, "started": datetime.now().astimezone().isoformat(timespec="seconds"),
                   "k6_timeout_s": args.timeout, "targets_file": "targets-%d.json" % args.scale}, fh, indent=1)
    log("scale %d, %d routes, part %s" % (args.scale, len(picked), args.part))
    counts = table_counts(targets["database"])
    with open(os.path.join(args.out, "table-counts.json"), "w") as fh:
        json.dump({"database": targets["database"], "counts": counts, "taken": datetime.now().astimezone().isoformat(timespec="seconds")}, fh, indent=1)
    log("rows in the N tables: " + ", ".join("%s %d" % (k, v) for k, v in sorted(counts.items())))
    if args.part in ("both", "http"):
        http_part(args, root, picked, targets, log)
    if args.part in ("both", "inproc"):
        inproc_part(args, root, picked, targets, log)
    log("done")
    return 0


# ---------------------------------------------------------------- report

def read_jsonl(path: str) -> list[dict[str, Any]]:
    if not os.path.exists(path):
        return []
    with open(path) as fh:
        return [json.loads(line) for line in fh if line.strip()]


def build_report(scale: int, http: list[dict], inproc: list[dict], estimate: dict, prediction: dict) -> dict[str, Any]:
    coef = prediction["calibration"]["coefficients"]
    c_q, c_row = float(coef["c_q"]), float(coef["c_row"])
    est = estimate_routes(estimate)
    null = next((r["profile"] for r in http if r["kind"] == "null"), None)
    idle = next((r for r in http if r["kind"] == "idle"), None)
    routes = []
    seen = []
    for row in http:
        if row["kind"] == "request" and row["route"] not in seen:
            seen.append(row["route"])
    for route in seen:
        cold = [r for r in http if r["kind"] == "request" and r["route"] == route and r["phase"] == "cold"]
        att = [a for a in inproc if a["route"] == route and a["attempt"] == 2]
        first = [a for a in inproc if a["route"] == route and a["attempt"] == 1]
        e = est.get(route)
        cpu_s = [r["net"]["cpu_net_usec"] / 1e6 for r in cold]
        m_cpu = median_of(cpu_s) if cpu_s else None
        drift_med = median_of([r["net"]["idle_drift_usec"] for r in cold]) if cold else 0.0
        floor_null = bool(m_cpu is not None and null is not None and below_floor(m_cpu * 1e6, null, drift_med))
        scattered = scatter_exceeds_value(cpu_s)
        floor = floor_null or scattered
        m_q = median_of([a["queries"] for a in att]) if att else None
        m_rows = median_of([a["rows_examined"] for a in att if a["rows_examined"] is not None]) if att else None
        entry: dict[str, Any] = {
            "route": route,
            "http_status": [r["result"]["status"] for r in cold],
            "http_ms": [round(r["result"]["duration_ms"]) for r in cold],
            "body_bytes": [r["result"]["body_bytes"] for r in cold],
            "other_requests_seen": [r["nginx_requests_between_probes"] - 2 for r in cold],
            "cpu_net_s": cpu_s,
            "cpu_median_s": m_cpu,
            "cpu_below_measurement_floor": floor,
            "cpu_below_floor_by_null_rule": floor_null,
            "cpu_readings_scatter_more_than_median": scattered,
            "cpu_minus_null_only_s": [r["net"]["cpu_minus_null_usec"] / 1e6 for r in cold],
            "idle_drift_s": [r["net"]["idle_drift_usec"] / 1e6 for r in cold],
            "php_cpu_s": [r["result"]["php_cpu_usec"] / 1e6 for r in cold],
            "answered_after_s": [round(r["result"]["answered_after_s"], 2) for r in cold],
            "php_finished_after_answer_s": [round(r["result"]["settle"]["waited_s"], 1) for r in cold],
            "fpm_questions_net": [r["net"]["questions_net"] for r in cold],
            "fpm_com_select_net": [r["net"].get("counters_net", {}).get("Com_select", 0.0) for r in cold],
            "fpm_handlers_net": [r["net"]["handlers_net"] for r in cold],
            "inproc_cold_queries": [a["queries"] for a in att],
            "inproc_cold_rows_examined": [a["rows_examined"] for a in att],
            "inproc_cold_status": [a["status"] for a in att],
            "inproc_first_queries": [a["queries"] for a in first],
            "inproc_first_rows_examined": [a["rows_examined"] for a in first],
            "measured_queries": m_q, "measured_rows": m_rows,
            # the database's own count beside the in-process count: the net rise of Questions over one request, less the statements
            # the cold in-process request counted (php-fpm boots the app from nothing, so one or two more are expected)
            "fpm_questions_minus_counted_queries": [q - m_q for q in [r["net"]["questions_net"] for r in cold]] if m_q is not None else [],
            "processlist_after_window": [r.get("processlist_after_window") for r in cold],
        }
        if e is not None:
            est_cpu = per_request_cpu(c_q, c_row, e["queries"], e["rows"])
            est_cpu_safe = per_request_cpu(c_q, c_row, e["queries"], e["rows_safe"])
            meas_cpu_from_counts = per_request_cpu(c_q, c_row, m_q, m_rows) if m_q is not None and m_rows is not None else None
            entry["estimate"] = {"queries": e["queries"], "rows": e["rows"], "rows_safe": e["rows_safe"], "marked": e["marked"],
                                 "cpu_s": est_cpu, "cpu_s_safe": est_cpu_safe}
            entry["measured_cpu_from_counts_s"] = meas_cpu_from_counts
            entry["estimate_over_measured"] = {
                "rows_point": ratio(e["rows"], m_rows), "rows_safe": ratio(e["rows_safe"], m_rows),
                "queries": ratio(e["queries"], m_q),
                "cpu_point": None if floor else ratio(est_cpu, m_cpu),
                "cpu_safe": None if floor else ratio(est_cpu_safe, m_cpu),
                # the ratio as it would have been, kept beside the withheld one (a rule added after seeing a reading must not hide it)
                "cpu_point_if_not_withheld": ratio(est_cpu, m_cpu) if floor else None,
                "cpu_safe_if_not_withheld": ratio(est_cpu_safe, m_cpu) if floor else None,
                "cpu_withheld_because": [w for w, on in (("below the measurement floor (empty-window / idle-drift rule)", floor_null),
                                                         ("the three readings scatter by more than their median (rule added after the 100,000-row reading)", scattered)) if on],
                "cpu_point_vs_converted_counts": ratio(est_cpu, meas_cpu_from_counts),
            }
        routes.append(entry)
    return {"scale": scale, "coefficients": {"c_q": c_q, "c_row": c_row},
            "null_window": null, "idle_rate_usec_per_s": None if idle is None else idle["rate_usec_per_s"],
            "conversion": "estimated DB CPU seconds of one request = c_q * queries + c_row * rows (spec section 5)",
            "routes": routes}


def fmt(v: Any, digits: int = 3) -> str:
    if v is None:
        return "n/a"
    if isinstance(v, float):
        return ("%." + str(digits) + "g") % v
    return str(v)


def cpu_cell(eo: dict[str, Any]) -> str:
    """The CPU ratio column: the numbers, or, where they were withheld, the number that would have stood and why it did not."""
    if eo.get("cpu_withheld_because"):
        return "withheld: would be %s / %s (%s)" % (fmt(eo.get("cpu_point_if_not_withheld")), fmt(eo.get("cpu_safe_if_not_withheld")),
                                                      "; ".join(eo["cpu_withheld_because"]))
    return "%s / %s" % (fmt(eo.get("cpu_point")), fmt(eo.get("cpu_safe")))


def render_markdown(rep: dict[str, Any]) -> str:
    lines = ["| route | status | ms (3) | queries meas. | rows meas. | DB CPU s (median of 3) | queries est ÷ meas | rows est ÷ meas (point / safe) | CPU est ÷ meas (point / safe) |",
             "| --- | --- | --- | --- | --- | --- | --- | --- | --- |"]
    for r in rep["routes"]:
        eo = r.get("estimate_over_measured", {})
        lines.append("| %s | %s | %s | %s | %s | %s | %s | %s / %s | %s |" % (
            r["route"], ",".join(map(str, r["http_status"])), ",".join(map(str, r["http_ms"])), fmt(r["measured_queries"]),
            fmt(r["measured_rows"]), fmt(r["cpu_median_s"]) + (" (below the measurement floor)" if r["cpu_below_measurement_floor"] else ""), fmt(eo.get("queries")), fmt(eo.get("rows_point")), fmt(eo.get("rows_safe")),
            cpu_cell(eo)))
    return "\n".join(lines) + "\n"


def report(args) -> int:
    http = read_jsonl(os.path.join(args.dir, "http.jsonl"))
    inproc = read_jsonl(os.path.join(args.dir, "inproc.jsonl"))
    with open(args.estimate) as fh:
        estimate = json.load(fh)
    with open(args.prediction) as fh:
        prediction = json.load(fh)
    rep = build_report(args.scale, http, inproc, estimate, prediction)
    out = args.out or args.dir
    with open(os.path.join(out, "table.json"), "w") as fh:
        json.dump(rep, fh, indent=1, sort_keys=True)
        fh.write("\n")
    with open(os.path.join(out, "table.md"), "w") as fh:
        fh.write(render_markdown(rep))
    print(render_markdown(rep))
    return 0


def cold_requests(http: list[dict], route_contains: str) -> list[dict]:
    return [r for r in http if r["kind"] == "request" and r["phase"] == "cold" and route_contains in r["route"]]


def summarize_cold(rows: list[dict]) -> dict[str, Any]:
    """Per measure: the readings of the cold requests and their median (DB CPU net of the empty window and idle drift, the rows the
    server read, the statements it counted, PHP's CPU time and the elapsed time)."""
    def pick(f):
        vals = [f(r) for r in rows]
        return {"readings": vals, "median": median_of(vals)}
    return {
        "n": len(rows),
        "http_status": [r["result"]["status"] for r in rows],
        "elapsed_s": pick(lambda r: r["result"]["answered_after_s"]),
        "php_cpu_s": pick(lambda r: r["result"]["php_cpu_usec"] / 1e6),
        "db_cpu_net_s": pick(lambda r: r["net"]["cpu_net_usec"] / 1e6),
        "db_cpu_minus_null_only_s": pick(lambda r: r["net"]["cpu_minus_null_usec"] / 1e6),
        "handler_reads": pick(lambda r: r["net"]["handlers_net"]),
        "questions": pick(lambda r: r["net"]["questions_net"]),
    }


def compare_records(cut: dict[str, Any], uncut: dict[str, Any]) -> dict[str, Any]:
    out: dict[str, Any] = {}
    for k in ("elapsed_s", "php_cpu_s", "db_cpu_net_s", "db_cpu_minus_null_only_s", "handler_reads", "questions"):
        c, u = cut[k]["median"], uncut[k]["median"]
        out[k] = {"cut": c, "uncut": u, "uncut_over_cut": ratio(u, c), "cut_over_uncut": ratio(c, u),
                  "uncut_minus_cut": u - c}
    return out


def compare_cmd(args) -> int:
    cut = summarize_cold(cold_requests(read_jsonl(os.path.join(args.cut, "http.jsonl")), args.route_contains))
    unc = summarize_cold(cold_requests(read_jsonl(os.path.join(args.uncut, "http.jsonl")), args.route_contains))
    res = {"route_contains": args.route_contains, "cut": cut, "uncut": unc, "compare": compare_records(cut, unc)}
    os.makedirs(args.out, exist_ok=True)
    with open(os.path.join(args.out, "compare.json"), "w") as fh:
        json.dump(res, fh, indent=1, sort_keys=True)
        fh.write("\n")
    lines = ["| measure | cut (median of %d) | uncut (median of %d) | uncut ÷ cut | cut share of uncut |" % (cut["n"], unc["n"]), "| --- | --- | --- | --- | --- |"]
    for k, v in res["compare"].items():
        lines.append("| %s | %s | %s | %s | %s |" % (k, fmt(v["cut"], 5), fmt(v["uncut"], 5), fmt(v["uncut_over_cut"]), fmt(v["cut_over_uncut"])))
    text = "\n".join(lines) + "\n"
    with open(os.path.join(args.out, "compare.md"), "w") as fh:
        fh.write(text)
    print(text)
    return 0


def counts_cmd(args) -> int:
    lab_out = os.environ.get("PEAK_LAB_OUT_DIR", os.path.expanduser("~/.cache/peak-lab/out"))
    targets = load_targets(args.scale, lab_out)
    counts = table_counts(targets["database"])
    os.makedirs(args.out, exist_ok=True)
    with open(os.path.join(args.out, "table-counts.json"), "w") as fh:
        json.dump({"database": targets["database"], "counts": counts, "taken": datetime.now().astimezone().isoformat(timespec="seconds")}, fh, indent=1)
    print(json.dumps(counts))
    return 0


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    r = sub.add_parser("run")
    r.add_argument("--scale", type=int, required=True)
    r.add_argument("--out", required=True)
    r.add_argument("--routes", default=None, help="1-based positions in the route list, comma separated (default all)")
    r.add_argument("--reps", type=int, default=3)
    r.add_argument("--part", choices=["both", "http", "inproc"], default="both")
    r.add_argument("--timeout", type=int, default=180, help="k6's timeout for one request, seconds")
    r.add_argument("--label", default="")
    q = sub.add_parser("report")
    q.add_argument("--scale", type=int, required=True)
    q.add_argument("--dir", required=True)
    q.add_argument("--estimate", required=True)
    q.add_argument("--prediction", required=True)
    q.add_argument("--out", default=None)
    k = sub.add_parser("compare")
    k.add_argument("--cut", required=True)
    k.add_argument("--uncut", required=True)
    k.add_argument("--route-contains", dest="route_contains", required=True)
    k.add_argument("--out", required=True)
    c = sub.add_parser("counts")
    c.add_argument("--scale", type=int, required=True)
    c.add_argument("--out", required=True)
    args = p.parse_args(argv)
    if args.cmd == "counts":
        return counts_cmd(args)
    if args.cmd == "compare":
        return compare_cmd(args)
    return run(args) if args.cmd == "run" else report(args)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
