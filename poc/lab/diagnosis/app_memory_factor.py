#!/usr/bin/env python3
"""Works out the factor of the memory side of the `app_limit_risk` mark (simulator-spec.md section 6.2 and 6.4) from records that already exist.

Reads only files (no lab, no Docker, no request, no database): the committed comparison of the second extrapolation test
(`docs/research/extrapolation-data-2/compare.json`, 200,000 rows, both apps), the committed route-level peak-memory formulas of BookStack
(`docs/research/extrapolation-data-2/bookstack/fits.json`) and the committed list of routes whose memory formula was checked
(`.../bookstack/memory-accuracy.json`), and the lab's own request records at counts already seen (10,000, 30,000, 50,000, 100,000, 200,000
rows). Nothing at 300,000 rows is read: there is none.

The mark compares the peak memory the model gives for one request (the route's fitted formula `a + b x N^k`, section 6.2) with the app's
memory limit (php `memory_limit`). The test records say how far a measured peak can be above the formula's value:

    factor = the largest  (measured peak memory of the cold request) / (the formula's value for it, committed before the measurement)

over the routes whose measured peak is at least FLOOR_BYTES. A route is marked when  factor x (its predicted peak memory)  reaches the limit.

Why a floor. The measured peaks fall in two groups with nothing between them (the script prints the gap): routes that stay at the fixed
memory of one request (up to about 50 MB) and routes whose memory grows with N (258 MB and more). The ratio of a route of the first group
is the ratio of two small constants (the one route of that group with a large ratio, 2.79, measured 17 MB against 6.1 MB predicted), not a
statement about growth, and no memory limit in use is within a factor of 2 of those peaks. The floor is a number inside the gap, written
here before the factor was read from it. Routes under the floor are listed, and the factor with no floor is printed beside it.

Why one factor for every route. The rule does not switch off for a route whose formula was outside +-30% in a test, or that no test
measured (section 6.2 keeps those out of the count N at which a line is reached; a mark that stays silent for them would be a miss that
the records already show can happen: the largest ratio, 1.51, is on routes that were outside). The factor is the largest ratio over routes
inside and outside +-30% alike, rounded up to two decimals, so that it leans to marking too much and not too little, the same direction as
the CPU time factor (D178).

What the figure leaves out. The measured peak is `memory_get_peak_usage()` of the request minus the memory in use before the request began
(`poc/runtime/src/Run/Requester.php`), so the memory of the booted application is not in it; the limit counts all of it. The factor does
not cover that (it is the ratio of two figures that both leave it out). It is a gap of the rule, written in the record.

Usage:
  app_memory_factor.py --repo=DIR [--out=DIR]    prints the table; writes <out>/factor.json when --out is given
  app_memory_factor.py --repo=DIR --check=FILE   exits 1 when FILE is not what this script gives now
"""
from __future__ import annotations

import argparse
import json
import math
import sys
from pathlib import Path

COMPARE = "docs/research/extrapolation-data-2/compare.json"
FITS = "docs/research/extrapolation-data-2/bookstack/fits.json"
ACCURACY = "docs/research/extrapolation-data-2/bookstack/memory-accuracy.json"
PER_REQUEST = "docs/research/lab-data/diagnosis/per-request"
MARK_RECORDS = {50000: "docs/research/lab-data/recheck-mark/mark-50000/http.jsonl", 200000: "docs/research/lab-data/recheck-mark/mark-200000/http.jsonl"}
LAB_COUNTS = (10000, 30000, 50000, 100000, 200000)

# A number inside the gap between the two groups of measured peaks (the gap is checked and printed by this script).
FLOOR_BYTES = 100_000_000

# The memory_limit of the lab's php-fpm in the records (`zz-lab.ini`, 512M; the application's own log of the 200,000-row falls says
# "Allowed memory size of 536870912 bytes exhausted").
LAB_LIMIT_BYTES = 536_870_912

# The routes of the lab (the same eight in every count) and the BookStack route each is the formula of.
LAB_ROUTES = (
    "GET /books/{bookSlug}/chapter/{chapterSlug}/references",
    "GET /books/{bookSlug}/export/zip",
    "GET /books/{bookSlug}/page/{pageSlug}/references",
    "GET /books/{slug}",
    "GET /books/{slug}/references",
    "GET /images/edit/{id}",
    "GET /oidc/callback",
    "GET /shelves/{slug}",
)

# What stopped a request in the lab's records, by (count, route). Only requests that did not answer 200/302 are here. The cause is what the
# records say, with the file that says it:
#   memory: the application's log of falls of the memory limit (recheck-ladder-b/status500/, section 4.4 of docs/research/lab-backtest-2.md)
#           for the book page at 200,000 rows
#   cpu_time: the ZIP route at 100,000 rows, cut by the 30 s execution limit (diagnosis/zip-limit/)
#   not_recorded: the ZIP route at 200,000 rows answered 500 after 52.6 s (the 100,000-row cut was after 53 s); the application's log of the
#           mark's own request was not kept, so the cause of that one request is not in the records (docs/research/lab-backtest-2.md section 4.5)
STOPPED = {
    (100000, "GET /books/{bookSlug}/export/zip"): "cpu_time",
    (200000, "GET /books/{bookSlug}/export/zip"): "not_recorded",
    (200000, "GET /books/{slug}"): "memory",
}


def load_json(repo: Path, rel: str):
    return json.loads((repo / rel).read_text())


def ratio_records(repo: Path):
    """One record per route that has a measured peak and a committed prediction, both apps of the second test."""
    compare = load_json(repo, COMPARE)
    out = []
    for app in compare["apps"]:
        for row in app["rows"]:
            pm = row.get("q", {}).get("peak_memory")
            if not pm or pm.get("cold_first") is None or not pm.get("predicted"):
                continue
            out.append({
                "app": app["app"],
                "route": row["route"],
                "predicted": pm["predicted"],
                "measured": pm["cold_first"],
                "ratio": pm["cold_first"] / pm["predicted"],
            })
    return out


def factor_of(records, floor=FLOOR_BYTES):
    """Largest measured/predicted ratio over the records with a measured peak of at least `floor`, rounded up to two decimals."""
    used = [r["ratio"] for r in records if r["measured"] >= floor]
    return math.ceil(max(used) * 100 - 1e-9) / 100


def gap_of(records, floor=FLOOR_BYTES):
    below = [r["measured"] for r in records if r["measured"] < floor]
    above = [r["measured"] for r in records if r["measured"] >= floor]
    return {"largest_measured_below_floor": max(below), "smallest_measured_at_or_above_floor": min(above)}


def evaluate_formula(a: float, b: float, k: float, n: int) -> float:
    """The same value as Judge\\CostFit::evaluate for n > 0."""
    return a + b * (float(n) ** k)


def accuracy_state(accuracy: dict, route: str) -> str:
    if route in accuracy["within"]:
        return "within"
    if route in accuracy["outside"]:
        return "outside"
    return "not_checked"


def lab_status(repo: Path, count: int, route: str):
    """The HTTP status of the lab's cold request of a route at a count already seen, from the committed records."""
    name = route
    if count in MARK_RECORDS:
        for line in (repo / MARK_RECORDS[count]).read_text().splitlines():
            d = json.loads(line)
            if d.get("kind") == "request" and d.get("route") == name and d.get("phase") == "cold":
                return d["result"]["status"]
        raise SystemExit("no cold request of %s in the mark record at %d rows" % (name, count))
    table = load_json(repo, "%s/%d/table.json" % (PER_REQUEST, count))
    for r in table["routes"]:
        if r["route"] == name:
            return r["http_status"][0]
    raise SystemExit("no record of %s at %d rows" % (name, count))


def judge(repo: Path, factor: float, limit: int = LAB_LIMIT_BYTES):
    fits = {r["route"]: r["quantities"]["peak_memory"] for r in load_json(repo, FITS)["routes"]}
    accuracy = load_json(repo, ACCURACY)
    rows = []
    for count in LAB_COUNTS:
        for route in LAB_ROUTES:
            f = fits[route]
            if f.get("fit_status") != "ok":
                raise SystemExit("no peak-memory formula for " + route)
            predicted = evaluate_formula(f["a"], f["b"], f["k"], count)
            bound = factor * predicted
            marked = bound >= limit
            cause = STOPPED.get((count, route))
            status = lab_status(repo, count, route)
            if cause is None and status not in (200, 302):
                raise SystemExit("a request that did not answer without a cause in STOPPED: %s at %d (%s)" % (route, count, status))
            fell = cause == "memory"
            rows.append({
                "rows": count,
                "route": route,
                "predicted_peak_bytes": predicted,
                "bound_bytes": bound,
                "share_of_limit": bound / limit,
                "marked": marked,
                "estimate_accuracy": accuracy_state(accuracy, route),
                "http_status": status,
                "stopped_by": cause,
                "fell_by_memory": fell,
                "outcome": ("hit" if marked else "miss") if fell else ("false_alarm" if marked else "true_negative"),
            })
    return rows


def tally(rows):
    t = {}
    for r in rows:
        t[r["outcome"]] = t.get(r["outcome"], 0) + 1
    return t


def build(repo: Path):
    records = ratio_records(repo)
    factor = factor_of(records)
    rows = judge(repo, factor)
    no_floor = factor_of(records, 0)
    within_only = factor_of([r for r in records if accuracy_state_of_record(repo, r) == "within"])
    return {
        "what": "factor of the memory side of the app_limit_risk mark: the largest (measured peak memory) / (committed prediction) over the routes of the second extrapolation test whose measured peak is at least the floor",
        "floor_bytes": FLOOR_BYTES,
        "gap_between_the_groups": gap_of(records),
        "factor": factor,
        "factor_with_no_floor": no_floor,
        "factor_over_the_routes_within_30_percent_only": within_only,
        "records_at_or_above_the_floor": sorted(
            ({k: r[k] for k in ("app", "route", "predicted", "measured", "ratio")} for r in records if r["measured"] >= FLOOR_BYTES),
            key=lambda r: (-r["ratio"], r["app"], r["route"]),
        ),
        "records_under_the_floor": sorted(
            ({k: r[k] for k in ("app", "route", "predicted", "measured", "ratio")} for r in records if r["measured"] < FLOOR_BYTES),
            key=lambda r: (-r["ratio"], r["app"], r["route"]),
        ),
        "rule": "marked when factor x (the predicted peak memory of one request, section 6.2) >= the memory limit; a route with no peak-memory estimate is not judged",
        "memory_limit_bytes_of_the_records": LAB_LIMIT_BYTES,
        "judged_on_the_lab_records": rows,
        "tally_on_the_lab_records": tally(rows),
    }


def accuracy_state_of_record(repo: Path, rec) -> str:
    """Only BookStack routes have a checked list in the repository; the verification app's routes are judged from its own record the same way."""
    path = "docs/research/extrapolation-data-2/%s/memory-accuracy.json" % ("bookstack" if rec["app"] == "bookstack" else "fixtures")
    return accuracy_state(load_json(repo, path), rec["route"])


def main(argv=None) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--repo", required=True)
    p.add_argument("--out")
    p.add_argument("--check")
    a = p.parse_args(argv)
    result = build(Path(a.repo))
    text = json.dumps(result, indent=2, sort_keys=False) + "\n"
    if a.check:
        return 0 if Path(a.check).read_text() == text else 1
    if a.out:
        Path(a.out).mkdir(parents=True, exist_ok=True)
        (Path(a.out) / "factor.json").write_text(text)
    for r in result["records_at_or_above_the_floor"][:8]:
        print("%-9s %-50s predicted %13.0f measured %13.0f ratio %.3f" % (r["app"], r["route"][:50], r["predicted"], r["measured"], r["ratio"]))
    print("gap", result["gap_between_the_groups"])
    print("factor", result["factor"], "(no floor %s, routes within +-30%% only %s)" % (result["factor_with_no_floor"], result["factor_over_the_routes_within_30_percent_only"]))
    print("tally on the lab records", result["tally_on_the_lab_records"])
    return 0


if __name__ == "__main__":
    sys.exit(main())
