#!/usr/bin/env python3
"""Works out the factor of the `app_limit_risk` mark (simulator-spec.md section 6.4) from records that already exist.

Reads only files (no lab, no Docker, no request): the per-request records at 10,000, 30,000 and 100,000 rows
(`docs/research/lab-data/diagnosis/per-request/<rows>/table.json`) and the ZIP route's uncut record
(`docs/research/lab-data/diagnosis/zip-limit/compare.json`). Nothing at 50,000 or 200,000 rows is read: there is none.

The mark compares the database time of one request that the model gives (c_q x statements + c_row x rows, the calibration's own
conversion, which is meant to be the database's CPU time) with the app's execution limit. php's limit counts the CPU time of the php
process, not the clock: the ZIP route at 100,000 rows was cut at 30 s of php CPU time after 53 s of elapsed time. So the database
time has to be turned into a php CPU time, and the records say how far apart the two are:

    factor = the largest  (php CPU time of one request) / (the database CPU time measured for that request)

over the records whose database CPU reading was not withheld (neither under the measurement floor nor scattered). The denominator is
the measured database CPU time, not a time converted from counts: the calibration's conversion is being corrected, and a factor that
takes in the old conversion's bias would be wrong once the conversion is right. A request that was cut by the limit is censored (its
php CPU time is the limit), so the uncut record of the same route (both times) stands in for it. The factor is rounded up to two
decimals. A route is marked when  factor x (its database time as the model gives it, larger of the point and the safe side) reaches the
limit.

Usage:
  app_limit_factor.py --repo=DIR [--limit=30] [--out=DIR]    prints the table; writes <out>/factor.json when --out is given
  app_limit_factor.py --repo=DIR --check=FILE                exits 1 when FILE is not what this script gives now
"""
from __future__ import annotations

import argparse
import json
import math
import statistics
import sys
from pathlib import Path

COUNTS = (10000, 30000, 100000)
PER_REQUEST = "docs/research/lab-data/diagnosis/per-request"
ZIP_COMPARE = "docs/research/lab-data/diagnosis/zip-limit/compare.json"
CUT_STATUS = 500


def load(repo: Path):
    tables = {n: json.loads((repo / PER_REQUEST / str(n) / "table.json").read_text()) for n in COUNTS}
    zip_uncut = json.loads((repo / ZIP_COMPARE).read_text())
    return tables, zip_uncut


def records(tables, zip_uncut):
    """One record per (count, route): the php CPU time, the measured database CPU time, the estimate's two readings."""
    out = []
    uncut_php = zip_uncut["compare"]["php_cpu_s"]["uncut"]
    uncut_db = zip_uncut["compare"]["db_cpu_net_s"]["uncut"]
    for n, t in tables.items():
        for r in t["routes"]:
            name = r["route"].split(" ", 1)[1]
            cut = r["http_status"][0] == CUT_STATUS
            php = statistics.median(r["php_cpu_s"])
            db = r["cpu_median_s"]
            if cut:
                if "export/zip" not in name:
                    raise SystemExit("a cut request of a route with no uncut record: " + name)
                php, db = uncut_php, uncut_db
            withheld = bool(r["cpu_below_measurement_floor"] or r["cpu_readings_scatter_more_than_median"])
            e = r["estimate"]
            out.append({
                "rows": n,
                "route": name,
                "cut_by_limit": cut,
                "php_cpu_s": php,
                "php_cpu_s_is_uncut_stand_in": cut,
                "db_cpu_s": db,
                "db_cpu_reading_withheld": withheld,
                "ratio": (php / db) if (db > 0 and not withheld) else None,
                "estimate_point_s": e["cpu_s"],
                "estimate_safe_s": e["cpu_s_safe"],
            })
    return out


def factor_of(recs):
    ratios = [r["ratio"] for r in recs if r["ratio"] is not None]
    return math.ceil(max(ratios) * 100) / 100


def judge(recs, factor, limit):
    """What the rule says for every record, and what happened (a request is cut when it was, or when its php CPU time reached the limit)."""
    rows = []
    for r in recs:
        bound = factor * max(r["estimate_point_s"], r["estimate_safe_s"])
        marked = bound >= limit
        cut = r["cut_by_limit"]
        rows.append({
            "rows": r["rows"],
            "route": r["route"],
            "php_cpu_bound_s": bound,
            "marked": marked,
            "cut_by_limit": cut,
            "outcome": ("hit" if marked else "miss") if cut else ("false_alarm" if marked else "true_negative"),
        })
    return rows


def build(repo: Path, limit: float):
    tables, zip_uncut = load(repo)
    recs = records(tables, zip_uncut)
    factor = factor_of(recs)
    verdict = judge(recs, factor, limit)
    # the factor from the two smaller counts only, applied to the count it did not see
    small = [r for r in recs if r["rows"] != 100000]
    f_small = factor_of(small)
    held_out = [v for v in judge(recs, f_small, limit) if v["rows"] == 100000]
    tally = {}
    for v in verdict:
        tally[v["outcome"]] = tally.get(v["outcome"], 0) + 1
    return {
        "what": "factor of the app_limit_risk mark: the largest (php CPU time) / (measured database CPU time) of the per-request records whose database CPU reading was not withheld",
        "limit_s_of_the_records": limit,
        "factor": factor,
        "records_used": [{k: r[k] for k in ("rows", "route", "php_cpu_s", "php_cpu_s_is_uncut_stand_in", "db_cpu_s", "ratio")} for r in recs if r["ratio"] is not None],
        "rule": "marked when factor x max(point, safe side) of the database time the model gives for one request >= the limit",
        "judged_on_the_records": verdict,
        "tally_on_the_records": tally,
        "factor_from_10000_and_30000_only": f_small,
        "judged_at_100000_with_that_factor": held_out,
    }


def main(argv=None) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--repo", required=True)
    p.add_argument("--limit", type=float, default=30.0)
    p.add_argument("--out")
    p.add_argument("--check")
    a = p.parse_args(argv)
    result = build(Path(a.repo), a.limit)
    text = json.dumps(result, indent=2, sort_keys=False) + "\n"
    if a.check:
        return 0 if Path(a.check).read_text() == text else 1
    if a.out:
        Path(a.out).mkdir(parents=True, exist_ok=True)
        (Path(a.out) / "factor.json").write_text(text)
    for r in result["records_used"]:
        print(f"{r['rows']:>7} {r['route'][:38]:38} php {r['php_cpu_s']:8.3f}s  database {r['db_cpu_s']:8.3f}s  ratio {r['ratio']:.3f}")
    print(f"factor {result['factor']}   tally {result['tally_on_the_records']}   factor from the two smaller counts {result['factor_from_10000_and_30000_only']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
