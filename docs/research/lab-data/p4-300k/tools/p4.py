#!/usr/bin/env python3
"""P4: per-statement reads of two BookStack routes at 300,000 rows, before and after a rebuild of `references`.

Read-only on the lab's database except for the step named `regen` (the app's own maintenance action) and `analyze`.
It sends nothing but what the runtime's `measure-one` sends (every request inside a transaction that is rolled back) and reads
the server's counters as root. No setting of the server is changed.

  p4.py state <label>      measure the two routes in the database as it is now: records go to <out>/<label>/
  p4.py regen-rb           the maintenance route measured by measure-one (rolled back: the data stays, the statistics may move)
  p4.py regen-real         the app's own `ReferenceStore::updateForAll()` committed (same call as the route and the artisan command)
  p4.py analyze            ANALYZE TABLE on every table of the database (the lab's usual way, the server's default sample)
"""
import json, os, subprocess, sys, time, csv, io
from datetime import datetime

OUT = os.path.expanduser("~/.cache/peak-lab/out/p4-300k")
SCALE = 300000
ACT_AS = "BookStack\\Users\\Models\\User:1:web"
ROUTES = {
    "shelf": "/shelves/1",
    "page": "/books/1/page/3",
}

def load_env():
    env = {}
    for line in open(os.path.expanduser("~/.cache/peak-lab/state.env")):
        line = line.strip()
        if "=" in line:
            k, v = line.split("=", 1)
            env[k] = v
    return env

ENV = load_env()
DB = "peak_sim_measure_%s_lab_%d" % (ENV["PEAK_MEASURE_RUN_ID"], SCALE)

def now():
    return datetime.now().astimezone().isoformat(timespec="seconds")

def mysql(sql, batch=True):
    cmd = ["docker", "exec", "-e", "MYSQL_PWD=" + ENV["PEAK_MEASURE_DB_ROOT_PASSWORD"], "peak-lab-db", "mysql", "-uroot"]
    if batch:
        cmd += ["-N", "-B"]
    cmd += ["-e", sql]
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        raise SystemExit("mysql failed: " + r.stderr[:500])
    return r.stdout

def rows(sql):
    return [l.split("\t") for l in mysql(sql).splitlines() if l != ""]

def handler_status():
    d = {}
    for k, v in rows("SHOW GLOBAL STATUS WHERE Variable_name LIKE 'Handler_read%' OR Variable_name IN ('Questions','Innodb_rows_read')"):
        d[k] = int(v)
    return d

def digests():
    d = {}
    for dg, schema, text, cnt, ex, sent in rows(
        "SELECT IFNULL(digest,'NULL'), IFNULL(schema_name,'NULL'), REPLACE(REPLACE(IFNULL(LEFT(digest_text,300),''),'\\t',' '),'\\n',' '), "
        "count_star, sum_rows_examined, sum_rows_sent FROM performance_schema.events_statements_summary_by_digest"):
        d[(dg, schema)] = (text, int(cnt), int(ex), int(sent))
    return d

def stats_snapshot(path):
    out = mysql("SELECT table_name, n_rows, clustered_index_size, sum_of_other_index_sizes, last_update FROM mysql.innodb_table_stats "
                "WHERE database_name='%s' ORDER BY table_name" % DB)
    open(path, "w").write("table\tn_rows\tclustered_index_size\tsum_of_other_index_sizes\tlast_update_utc\n" + out)

def counts_snapshot(path):
    out = ["table\tcount"]
    for t in ("references", "entities", "views", "deletions", "activities"):
        out.append("%s\t%s" % (t, mysql("SELECT COUNT(*) FROM `%s`.`%s`" % (DB, t)).strip()))
    open(path, "w").write("\n".join(out) + "\n")

def measure_one(uri, route, phases, tap=None, method="GET", extra=()):
    cmd = ["docker", "exec", "-e", "PEAK_MEASURE_PROFILE=bookstack", "-e", "PEAK_MEASURE_REQUEST_TIMEOUT=600", "peak-lab-tools",
           "php", "-d", "memory_limit=-1", "/peak-sim/poc/runtime/bin/measure-one", "--scale=%d" % SCALE, "--uri=" + uri, "--route=" + route,
           "--method=" + method, "--html", "--phases=" + phases, "--act-as=" + ACT_AS]
    cmd += list(extra)
    if tap:
        cmd.append("--tap-out=/out/p4-300k/" + tap)
    t0 = time.time()
    r = subprocess.run(cmd, capture_output=True, text=True)
    return r, time.time() - t0

def attempts(stdout):
    res = []
    for l in stdout.splitlines():
        l = l.strip()
        if l.startswith("{") and '"attempt"' in l:
            res.append(json.loads(l))
    return res

def diff_digests(a, b):
    out = {}
    for k, v in b.items():
        base = a.get(k, (v[0], 0, 0, 0))
        dc, de, ds = v[1] - base[1], v[2] - base[2], v[3] - base[3]
        if dc or de or ds:
            out[k] = {"digest": k[0], "schema": k[1], "text": v[0], "d_count": dc, "d_rows_examined": de, "d_rows_sent": ds}
    return out

def ps_segments():
    """Per measure-one process (a thread that has a START TRANSACTION): for each transaction (= one attempt) the list of
    `statement/com/Execute` events in order: (rows_examined, rows_sent). From performance_schema.events_statements_history_long
    (its consumer was switched on for this work; see ps-consumers.txt). Execute events carry no SQL text in this server."""
    by_thread = {}
    for tid, eid, name, rex, rsent in rows("SELECT thread_id, event_id, event_name, rows_examined, rows_sent FROM "
                                           "performance_schema.events_statements_history_long ORDER BY thread_id, event_id"):
        by_thread.setdefault(tid, []).append((name, int(rex), int(rsent)))
    out = []
    for tid, evs in by_thread.items():
        if not any(n == "statement/sql/begin" for n, _, _ in evs):
            continue
        segs, cur = [], None
        for n, rex, rs in evs:
            if n == "statement/sql/begin":
                cur = []
            elif n in ("statement/sql/rollback", "statement/sql/commit"):
                if cur is not None:
                    segs.append(cur)
                cur = None
            elif cur is not None and n == "statement/com/Execute":
                cur.append([rex, rs])
        out.append({"thread_id": tid, "attempts": segs})
    return out

def run_with_counters(d, name, uri, route, phases, extra=(), method="GET"):
    """one untapped run, with the server's counters read just before and just after (outside the run)"""
    mysql("TRUNCATE TABLE performance_schema.events_statements_history_long")
    dg0 = digests(); h0 = handler_status(); t_start = now()
    r, wall = measure_one(uri, route, phases, method=method, extra=extra)
    h1 = handler_status(); dg1 = digests(); t_end = now()
    ps = ps_segments()
    open(os.path.join(d, name + ".stdout"), "w").write(r.stdout)
    open(os.path.join(d, name + ".stderr"), "w").write(r.stderr)
    rec = {"name": name, "uri": uri, "method": method, "phases": phases, "started": t_start, "ended": t_end, "wall_s": round(wall, 1),
           "exit": r.returncode, "attempts": attempts(r.stdout),
           "global_status_delta": {k: h1[k] - h0[k] for k in h0},
           "ps_execute_events_by_attempt": ps,
           "digest_delta": sorted(diff_digests(dg0, dg1).values(), key=lambda x: -x["d_rows_examined"])}
    json.dump(rec, open(os.path.join(d, name + ".counters.json"), "w"), indent=1)
    return rec

def state(label, only=None):
    d = os.path.join(OUT, label)
    os.makedirs(d, exist_ok=True)
    sfx = "" if only is None else "-" + only
    stats_snapshot(os.path.join(d, "stats-before%s.tsv" % sfx))
    counts_snapshot(os.path.join(d, "counts-before%s.tsv" % sfx))
    summary = {"label": label, "database": DB, "started": now(), "routes": {}}
    for route, uri in ROUTES.items():
        if only is not None and route != only:
            continue
        # 1. the tapped run (statement by statement, with plans): warm-up then cold, as the third test measured
        r, wall = measure_one(uri, route, "warmup,cold", tap=label + "/" + route + ".tap.json")
        open(os.path.join(d, route + ".tap.stdout"), "w").write(r.stdout)
        open(os.path.join(d, route + ".tap.stderr"), "w").write(r.stderr)
        # 2. two untapped runs with the server's counters: warm-up alone, and warm-up + cold (cold = second minus first)
        w = run_with_counters(d, route + ".w", uri, route, "warmup")
        wc = run_with_counters(d, route + ".wc", uri, route, "warmup,cold")
        summary["routes"][route] = {
            "uri": uri,
            "tap_exit": r.returncode, "tap_wall_s": round(wall, 1),
            "tap_attempts": attempts(r.stdout),
            "w_attempts": w["attempts"], "wc_attempts": wc["attempts"],
        }
        print(route, "tap", [(a["attempt"], a["status"], a["queries"], a["rows_examined"]) for a in attempts(r.stdout)],
              "wc", [(a["attempt"], a["status"], a["queries"], a["rows_examined"]) for a in wc["attempts"]], flush=True)
    stats_snapshot(os.path.join(d, "stats-after%s.tsv" % sfx))
    counts_snapshot(os.path.join(d, "counts-after%s.tsv" % sfx))
    summary["ended"] = now()
    json.dump(summary, open(os.path.join(d, "summary%s.json" % sfx), "w"), indent=1)

def regen_rb():
    d = os.path.join(OUT, "regen-rb"); os.makedirs(d, exist_ok=True)
    stats_snapshot(os.path.join(d, "stats-before.tsv"))
    counts_snapshot(os.path.join(d, "counts-before.tsv"))
    rec = run_with_counters(d, "regen", "/settings/maintenance/regenerate-references", "regen", "cold",
                            method="POST", extra=["--csrf", "--form"])
    time.sleep(70)  # let the statistics thread settle after a large change (the server recalculates in the background)
    stats_snapshot(os.path.join(d, "stats-after-70s.tsv"))
    counts_snapshot(os.path.join(d, "counts-after.tsv"))
    print("regen-rb", [(a["attempt"], a["status"], a["queries"], a["rows_examined"], a["class"], a["detail"]) for a in rec["attempts"]])

PHP_REAL = r"""<?php
declare(strict_types=1);
// The app's own maintenance action, committed (no transaction around it): what the route and `artisan bookstack:regenerate-references` call.
require '/peak-sim/poc/runtime/bootstrap.php';
use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
$config = MeasureConfig::fromEnv(getenv());
$booted = (new AppBooter())->boot($config, %d);
$app = $booted->app;
$t0 = microtime(true);
$app->make('BookStack\\References\\ReferenceStore')->updateForAll();
fwrite(STDOUT, json_encode(['done' => true, 'seconds' => round(microtime(true) - $t0, 1), 'db' => $booted->dbName]) . "\n");
"""

def regen_real():
    d = os.path.join(OUT, "regen-real"); os.makedirs(d, exist_ok=True)
    open(os.path.join(OUT, "regen_real.php"), "w").write(PHP_REAL % SCALE)
    stats_snapshot(os.path.join(d, "stats-before.tsv"))
    counts_snapshot(os.path.join(d, "counts-before.tsv"))
    h0 = handler_status(); t0 = now()
    r = subprocess.run(["docker", "exec", "-e", "PEAK_MEASURE_PROFILE=bookstack", "peak-lab-tools", "php", "-d", "memory_limit=-1", "/out/p4-300k/regen_real.php"],
                       capture_output=True, text=True)
    t1 = now(); h1 = handler_status()
    open(os.path.join(d, "regen.stdout"), "w").write(r.stdout)
    open(os.path.join(d, "regen.stderr"), "w").write(r.stderr)
    json.dump({"started": t0, "ended": t1, "exit": r.returncode, "global_status_delta": {k: h1[k] - h0[k] for k in h0}},
              open(os.path.join(d, "regen.json"), "w"), indent=1)
    time.sleep(70)
    stats_snapshot(os.path.join(d, "stats-after-70s.tsv"))
    counts_snapshot(os.path.join(d, "counts-after.tsv"))
    print("regen-real exit", r.returncode, r.stdout[-300:], r.stderr[-300:])

def analyze(label="analyze"):
    d = os.path.join(OUT, label); os.makedirs(d, exist_ok=True)
    stats_snapshot(os.path.join(d, "stats-before.tsv"))
    tables = [r[0] for r in rows("SELECT table_name FROM information_schema.tables WHERE table_schema='%s' AND table_type='BASE TABLE' ORDER BY 1" % DB)]
    t0 = now()
    out = mysql("USE `%s`; ANALYZE TABLE %s" % (DB, ",".join("`%s`" % t for t in tables)))
    open(os.path.join(d, "analyze-output.txt"), "w").write("# started %s ended %s\n%s" % (t0, now(), out))
    stats_snapshot(os.path.join(d, "stats-after.tsv"))
    print("analyze: %d tables" % len(tables))

if __name__ == "__main__":
    cmd = sys.argv[1]
    if cmd == "state":
        state(sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else None)
    elif cmd == "regen-rb":
        regen_rb()
    elif cmd == "regen-real":
        regen_real()
    elif cmd == "analyze":
        analyze(sys.argv[2] if len(sys.argv) > 2 else "analyze")
    else:
        raise SystemExit(__doc__)
