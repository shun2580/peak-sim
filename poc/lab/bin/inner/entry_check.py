"""Runs inside bin/entry-check (which has sourced bin/_lab.sh, so the container names are in the environment). See that file for what this does.

The rule by which a route passes (it is about whether the entry is sound, and does not depend on how many statements or rows the request took):
  - the HTTP status is 200;
  - the login the targets file sends is the login the route list names (guest is `none`);
  - the database counted no write statement for the request, and no latch was left (the entry's guard saw nothing outside the list of tables it may write).
The statements the database counted (`Com_select`, less what an empty window counts) and the rows the server read (the seven `Handler_read_*` counters, less
what an empty window reads) are RECORDED beside the capture's figures for the route at this count, as differences, and are not judged. php-fpm boots the app and
logs the login in for every request, and the database counts that; the capture's counter starts after the boot. That is work a real store does, so a number is not
a verdict. What the extra statements are is read from the statement record (docs/research/lab-data), not from a limit set before the request.
A route with no capture figure at this count has no difference recorded (--no-capture-ok is still accepted, for a count the capture did not take).
"""
import json
import os
import re
import statistics
import subprocess
import sys
import time

HANDLERS = ["Handler_read_first", "Handler_read_key", "Handler_read_last", "Handler_read_next", "Handler_read_prev", "Handler_read_rnd", "Handler_read_rnd_next"]
COUNTERS = HANDLERS + ["Questions", "Com_select", "Com_insert", "Com_update", "Com_delete", "Com_replace", "Com_begin", "Com_commit", "Com_rollback"]
STATUS_SQL = "SHOW GLOBAL STATUS WHERE Variable_name IN (" + ",".join("'%s'" % c for c in COUNTERS) + ")"
NULL_WINDOWS = 5
SETTLE_S = 2.0
PROBE_JS = """import http from 'k6/http';
export const options = { vus: 1, iterations: 1, discardResponseBodies: true };
export default function () {
  const r = http.request(__ENV.PROBE_METHOD || 'GET', __ENV.PROBE_URL, null, { headers: JSON.parse(__ENV.PROBE_HEADERS || '{}'), redirects: 0, timeout: '120s' });
  console.log('PROBE ' + JSON.stringify({ status: r.status, location: (r.headers && (r.headers['Location'] || r.headers['location'])) || null, refused: (r.headers && (r.headers['X-Lab-Refused'] || r.headers['x-lab-refused'])) || null, error: r.error || null }));
}
"""
LATCH = "/scratch/peak-measure-lab/contaminated.flag"


def die(code, message):
    sys.stderr.write("entry-check: " + message + "\n")
    sys.exit(code)


def sh(cmd, timeout=300):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, encoding="utf-8", errors="replace")


def env(name):
    value = os.environ.get(name, "")
    if not value:
        die(3, "%s is not set (bin/entry-check sources bin/_lab.sh)" % name)
    return value


DB = APP = K6 = OUT_LAB = None


def counters():
    r = sh(["docker", "exec", DB, "sh", "-c", 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -N -B -e "%s"' % STATUS_SQL], timeout=60)
    if r.returncode != 0:
        die(3, "cannot read the database's counters: " + r.stderr.strip()[:200])
    out = {}
    for line in r.stdout.splitlines():
        parts = line.split("\t")
        if len(parts) == 2 and parts[0] in COUNTERS and parts[1].isdigit():
            out[parts[0]] = int(parts[1])
    if set(out) != set(COUNTERS):
        die(3, "the database did not give every counter: " + ",".join(sorted(set(COUNTERS) - set(out))))
    return out


def window(action):
    before = counters()
    result = action()
    time.sleep(SETTLE_S)
    after = counters()
    return {k: after[k] - before[k] for k in COUNTERS}, result


def request(url, headers, method="GET"):
    r = sh(["docker", "exec", "-e", "PROBE_URL=" + url, "-e", "PROBE_METHOD=" + method, "-e", "PROBE_HEADERS=" + json.dumps(headers), K6, "k6", "run", "--quiet", "/out/entry_check_probe.js"], timeout=240)
    for line in (r.stdout + "\n" + r.stderr).splitlines():
        m = re.search(r"PROBE (\{.*\})", line)
        if m:
            body = m.group(1)
            if '\\"' in body:  # k6 writes the message as a quoted log field
                body = body.replace('\\"', '"')
            return json.loads(body)
    die(3, "k6 gave no result line: " + (r.stderr or r.stdout)[-300:])


def latch_present():
    return sh(["docker", "exec", APP, "test", "-e", LATCH]).returncode == 0


def handler_total(c):
    return sum(c[h] for h in HANDLERS)


def main(argv):
    global DB, APP, K6
    args = {}
    for a in argv:
        m = re.match(r"^--(scale|targets|capture|out|routes)=(.+)$", a)
        if m is None and a == "--no-capture-ok":
            args["no-capture-ok"] = "1"
            continue
        if m is None:
            die(2, "usage: entry-check --scale=N --targets=<targets-N.json> --capture=<capture.json> --out=<directory> [--routes=<route-list.json>] [--no-capture-ok]")
        args[m.group(1)] = m.group(2)
    for k in ("scale", "targets", "capture", "out"):
        if k not in args:
            die(2, "usage: entry-check --scale=N --targets=<targets-N.json> --capture=<capture.json> --out=<directory> [--routes=<route-list.json>]")
    if not re.fullmatch(r"[0-9]{1,7}", args["scale"]):
        die(2, "the scale must be a number")
    scale = args["scale"]
    root = env("PEAK_REPO_ROOT")
    routes_file = args.get("routes") or os.path.join(root, os.environ.get("PEAK_LAB_ROUTE_LIST") or "poc/lab/routes/route-list.json")
    DB, APP, K6 = env("PEAK_LAB_DB_CONTAINER"), env("PEAK_LAB_APP_CONTAINER"), env("PEAK_LAB_K6_CONTAINER")
    out_lab = env("PEAK_LAB_OUT_DIR")
    out = args["out"]
    if os.path.exists(os.path.join(out, "requests.tsv")):
        die(2, out + " already holds a requests.tsv; use another directory")
    if sh([os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "preflight")], timeout=300).returncode != 0:
        die(11, "the closed-network check failed; no request was sent")

    with open(routes_file) as fh:
        listed = json.load(fh)["selected"]
    with open(args["targets"]) as fh:
        targets = json.load(fh)
    with open(args["capture"]) as fh:
        capture = {r["route"]: r for r in json.load(fh)["routes"]}
    current = sh(["docker", "exec", APP, "printenv", "PEAK_LAB_SCALE"])
    if current.returncode != 0 or current.stdout.strip() != scale:
        die(3, "the app reads scale %s, not %s (bin/use-db %s first)" % (current.stdout.strip() or "?", scale, scale))
    os.makedirs(out, exist_ok=True)
    with open(os.path.join(out_lab, "entry_check_probe.js"), "w") as fh:
        fh.write(PROBE_JS)

    latch_before = latch_present()
    nulls = [window(lambda: None)[0] for _ in range(NULL_WINDOWS)]
    null = {k: int(statistics.median(n[k] for n in nulls)) for k in COUNTERS}
    null_steady = all(n == nulls[0] for n in nulls)
    base = targets["base_url"]
    rows = []
    for item in listed:
        route = item["route"]
        want_login = item.get("login")
        t = targets["routes"].get(route)
        row = {"route": route, "login": want_login, "path": None, "sent_login": None, "status": None, "location": None}
        cap = (capture.get(route) or {}).get("totals", {}).get(scale)
        row["capture_statements"] = None if cap is None else cap.get("statements")
        row["capture_rows"] = None if cap is None else cap.get("cold_rows_examined")
        problems = []
        if t is None:
            problems.append("the targets file has no entry for the route")
        else:
            headers = dict(targets.get("headers") or {}) | dict(t.get("headers") or {})
            sent = headers.get("X-Lab-Login")
            row["path"] = t["path"] + ("?" + "&".join("%s=%s" % kv for kv in t["query"].items()) if t.get("query") else "")
            row["sent_login"] = sent
            expected = {"guest": "none"}.get(want_login, want_login)
            if sent != expected:
                problems.append("the targets file sends login %r, the route list names %r" % (sent, want_login))
        compared = not (cap is None or row["capture_statements"] is None or row["capture_rows"] is None)
        row["compared"] = compared
        if not problems:
            counters_d, res = window(lambda: request(base + row["path"], headers))
            net = {k: counters_d[k] - null[k] for k in COUNTERS}
            row.update(status=res["status"], location=res["location"], error=res["error"],
                       statements=net["Com_select"], questions=net["Questions"], rows_read=handler_total(net),
                       writes=sum(net[k] for k in ("Com_insert", "Com_update", "Com_delete", "Com_replace")),
                       transaction_statements=sum(net[k] for k in ("Com_begin", "Com_commit", "Com_rollback")))
            if res["error"] is not None or res["status"] != 200:
                problems.append("the status is %s%s" % (res["status"], " -> " + res["location"] if res["location"] else ""))
            if compared:  # recorded, not judged
                row["statements_extra"] = row["statements"] - row["capture_statements"]
                row["rows_extra"] = row["rows_read"] - row["capture_rows"]
            if row["writes"]:
                problems.append("the database counted %d write statements" % row["writes"])
        row["problems"] = problems
        row["pass"] = not problems
        rows.append(row)
        sys.stderr.write("%s: %s\n" % (route, "ok" if row["pass"] else "; ".join(problems)))

    # one POST (405) and a login the lab does not offer (400, no statement)
    first = next((r for r in rows if r["path"]), None)
    extra_checks = []
    if first is not None:
        common = dict(targets.get("headers") or {})  # the Host the lab names for every request (none for the BookStack lab), as in the routes above
        c_post, res = window(lambda: request(base + first["path"], common | {"X-Lab-Login": first["sent_login"] or "none"}, "POST"))
        net = {k: c_post[k] - null[k] for k in COUNTERS}
        extra_checks.append({"check": "POST " + first["path"], "status": res["status"], "wanted": 405, "statements": net["Com_select"], "pass": res["status"] == 405 and net["Com_select"] == 0})
        c_bad, res = window(lambda: request(base + first["path"], common | {"X-Lab-Login": "no-such-login"}))
        net = {k: c_bad[k] - null[k] for k in COUNTERS}
        offered_list = bool(os.environ.get("PEAK_LAB_LOGINS"))
        wanted = 400 if offered_list else 200
        extra_checks.append({"check": "GET %s as a login that is not offered" % first["path"], "status": res["status"], "wanted": wanted, "refused": res["refused"],
                             "statements": net["Com_select"], "pass": res["status"] == wanted and (net["Com_select"] == 0 or not offered_list)})
    latch_after = latch_present()
    # What the app said about a refusal, kept in the record before the container goes: the latch file (the first reason, with the kind of record
    # a refused insert was for) and the app's own lines about the lab (`lab: refused`, `lab: contaminated`). Statement text only, no bound value but a type.
    if latch_after:
        r = sh(["docker", "exec", APP, "cat", LATCH])
        with open(os.path.join(out, "latch.txt"), "w") as fh:
            fh.write(r.stdout)
    r = sh(["docker", "logs", APP], timeout=120)
    with open(os.path.join(out, "app-lab-log.txt"), "w") as fh:
        for line in (r.stdout + "\n" + r.stderr).splitlines():
            if "lab: refused" in line or '"lab":' in line or "write_outside_allowed_tables" in line:
                fh.write(line[:1200] + "\n")

    with open(os.path.join(out, "requests.tsv"), "w") as fh:
        fh.write("route\tlogin\tsent_login\tpath\tstatus\tselects\tcapture_selects\trows_read\tcapture_rows\trows_extra\tpass\n")
        for r in rows:
            fh.write("\t".join(str(x) for x in (r["route"], r["login"], r["sent_login"], r["path"], r["status"], r.get("statements"), r["capture_statements"], r.get("rows_read"), r["capture_rows"], r.get("rows_extra"), "yes" if r["pass"] else "NO: " + "; ".join(r["problems"]))) + "\n")
    with open(os.path.join(out, "routes.tsv"), "w") as fh:
        for r in rows:
            fh.write("%s\t%s\t%s\n" % (r["route"], r["path"], r["login"]))
    summary = {"scale": int(scale), "latch_before": latch_before, "latch_after": latch_after, "null_window": null, "null_windows_identical": null_steady,
               "rule": {"passes_on": ["status 200", "the login the route list names", "no write statement", "no latch"], "statements_and_rows_vs_capture": "recorded, not judged", "settle_s": SETTLE_S},
               "routes": rows, "extra_checks": extra_checks, "no_capture_figure_allowed": "no-capture-ok" in args}
    with open(os.path.join(out, "summary.json"), "w") as fh:
        json.dump(summary, fh, indent=2, sort_keys=True)
        fh.write("\n")
    ok = all(r["pass"] for r in rows) and all(c["pass"] for c in extra_checks) and not latch_before and not latch_after
    print("ENTRY CHECK %s: %d of %d routes passed; latch before/after: %s/%s; extra checks passed: %d of %d" % (
        "OK" if ok else "FAILED", sum(r["pass"] for r in rows), len(rows), latch_before, latch_after, sum(c["pass"] for c in extra_checks), len(extra_checks)))
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main(sys.argv[1:])
