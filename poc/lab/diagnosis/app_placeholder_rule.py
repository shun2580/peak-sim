#!/usr/bin/env python3
"""Works out, from captures that already exist, what the placeholder side of the `app_limit_risk` mark says (reads files only: no lab, no request).

The rule (poc/runtime/src/Simulate/Predict/AppPlaceholderRisk.php): a statement carries `fixed + (values - fixed) x n / scale` values at the table size n
(rounded up), where `values` is the number of values the capture's representative statement was sent with, `fixed` the part of them no list holds and `scale`
the scale the representative was taken at. A route is marked when its largest statement carries more than the database accepts.

Inputs are the captures of `bin/capture`, kept outside the repository; they are named by their SHA-256 and the script stops when a file is not the one named.

    python3 -I poc/lab/diagnosis/app_placeholder_rule.py --bookstack=<capture.json> --bookstack-small=<capture.json> --fixtures=<capture.json> \
        --out=docs/research/lab-data/diagnosis/app-placeholder-risk
    python3 -I poc/lab/diagnosis/app_placeholder_rule.py ... --check      # does the committed rule.json equal what the script gives now
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

LIMIT = 65535  # MySQL's limit of placeholders in one prepared statement (extrapolation-test-3.md section 5; SQLSTATE 1390)

# the captures the rule is read from; the SHA-256 is of the file as bin/capture wrote it
BOOKSTACK_SHA = "69f08fcad32bdd31367747d8231dab69df32596a837876253169eda88807d839"  # scales 100 1000 10000 30000, the one the 300,000-row estimate read
BOOKSTACK_SMALL_SHA = "8bf76bbed94393410ec1d695d97478f7cf85011718ae7c0c9b90fed1420e9ce5"  # scales 100 1000 10000 (a capture of its own, used only to check the rule)
FIXTURES_SHA = "37a8dcd9383a157308d3e5651a6594cbe1d588f0321c9960be13b6d75af753b8"  # the verification app, scales 100 1000 10000 30000

BOOK_PAGE = "GET /books/{slug}"
BOOK_PAGE_STATEMENT = "sb13d9a7ef7"  # select * from `activities` where ((loggable_type = ? and loggable_id in (?)) or ...)
BULK = "POST /ok/bulk"

ROWS_BOOKSTACK = [10000, 30000, 50000, 100000, 200000, 300000]
ROWS_FIXTURES = [1000, 10000, 30000]

# what the records say happened to the route at each count (path of the record in the repository)
OBSERVED = {
    ("bookstack", 10000): (BOOK_PAGE, "ok", "docs/research/lab-data/diagnosis/per-request/README.md (HTTP 200)"),
    ("bookstack", 30000): (BOOK_PAGE, "ok", "docs/research/lab-data/diagnosis/per-request/README.md (HTTP 200)"),
    ("bookstack", 50000): (BOOK_PAGE, "ok", "docs/research/lab-backtest-3.md section 7 (no HTTP 500 in r-1 and r-2 at 50,000 rows, the second round of lab-backtest-2.md)"),
    ("bookstack", 100000): (BOOK_PAGE, "ok", "docs/research/lab-data/diagnosis/per-request/README.md (HTTP 200)"),
    ("bookstack", 200000): (BOOK_PAGE, "failed_1390", "docs/research/extrapolation-test-2.md (all four tries HTTP 500, SQLSTATE 1390)"),
    ("bookstack", 300000): (BOOK_PAGE, "failed_1390", "docs/research/lab-backtest-3.md section 4.4 and docs/research/extrapolation-test-3.md (SQLSTATE 1390)"),
    ("fixtures", 1000): (BULK, "ok", "docs/research/extrapolation-test-3.md (the capture at 1,000 rows ran)"),
    ("fixtures", 10000): (BULK, "ok", "docs/research/extrapolation-test-3.md (the capture at 10,000 rows ran)"),
    ("fixtures", 30000): (BULK, "failed_1390", "docs/research/extrapolation-test-3.md (HTTP 500, SQLSTATE 1390 at 30,000 rows)"),
}


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load(path: Path, expected: str, name: str) -> dict:
    got = sha256(path)
    if got != expected:
        raise SystemExit(f"{name}: {path} is not the capture the record names (sha256 {got}, expected {expected})")
    return json.loads(path.read_text())


def fixed_of(sql: str, values: int) -> int:
    """The values no list holds: the `?` of the representative's own text outside `in (?, ?, ...)` lists; none for an insert of rows (the rows are the list).
    The same as RowEstimator::placeholders. The text is the statement as the app sent it, not the type's pattern: a literal such as `limit 20` is a `?` in the
    pattern and no value in the statement."""
    if re.search(r"\bvalues\b", sql, re.I):
        return 0
    listed = sum(m.group(0).count("?") for m in re.finditer(r"\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)", sql, re.I))
    return min(max(0, values - listed), values)


def statements(capture: dict, app: str) -> list[dict]:
    out = []
    for r in capture["routes"]:
        for t in r["statement_types"]:
            rep = t.get("representative")
            if not rep or rep.get("bindings") is None or not isinstance(rep.get("sql"), str) or not rep.get("scale"):
                continue
            values = len(rep["bindings"])
            out.append({"app": app, "route": r["route"], "id": t["id"], "scale": int(rep["scale"]), "values": values, "fixed": fixed_of(rep["sql"], values)})
    return sorted(out, key=lambda s: (s["app"], s["route"], s["id"]))


def values_at(s: dict, n: int) -> int:
    """fixed + ceil((values - fixed) x n / scale), in whole numbers (the tool does it in floating point; for these figures the two agree)."""
    listed = s["values"] - s["fixed"]
    return s["fixed"] + -(-listed * n // s["scale"])


def judged(stmts: list[dict]) -> list[dict]:
    cells = []
    for app, rows in (("bookstack", ROWS_BOOKSTACK), ("fixtures", ROWS_FIXTURES)):
        mine = [s for s in stmts if s["app"] == app]
        for n in rows:
            per_route: dict[str, tuple[int, str]] = {}
            for s in mine:
                v = values_at(s, n)
                if s["route"] not in per_route or v > per_route[s["route"]][0]:
                    per_route[s["route"]] = (v, s["id"])
            marked = sorted(r for r, (v, _) in per_route.items() if v > LIMIT)
            cell = {
                "app": app,
                "rows": n,
                "largest_by_route": {r: {"values": v, "statement": i} for r, (v, i) in sorted(per_route.items()) if v >= 1000 or r in marked},
                "marked_routes": marked,
            }
            seen = OBSERVED.get((app, n))
            if seen:
                route, what, source = seen
                is_marked = route in marked
                cell["observed"] = {"route": route, "outcome": what, "source": source}
                cell["verdict"] = ("hit" if is_marked else "miss") if what == "failed_1390" else ("false_alarm" if is_marked else "correct_no_mark")
            other = [r for r in marked if not seen or r != seen[0]]
            cell["marked_routes_with_no_failure_on_record"] = other
            cells.append(cell)
    return cells


def build(bookstack: dict, small: dict, fixtures: dict) -> dict:
    stmts = statements(bookstack, "bookstack") + statements(fixtures, "fixtures")
    page = next(s for s in stmts if s["app"] == "bookstack" and s["id"] == BOOK_PAGE_STATEMENT and s["route"] == BOOK_PAGE)
    page_small = next(s for s in statements(small, "bookstack") if s["id"] == BOOK_PAGE_STATEMENT and s["route"] == BOOK_PAGE)
    # the fit of the two scales that carry the figure: a line through (10,000, 6,004) and (30,000, 18,004)
    slope = (page["values"] - page_small["values"]) / (page["scale"] - page_small["scale"])
    intercept = page["values"] - slope * page["scale"]
    predicted_small = values_at(page, page_small["scale"])
    bulk = {s["scale"]: s for s in stmts if s["app"] == "fixtures" and s["route"] == BULK and s["values"] > 100}
    return {
        "what": "The placeholder side of the app_limit_risk mark: the rule worked out from captures that already existed, and what it says on the counts already seen. Made by poc/lab/diagnosis/app_placeholder_rule.py.",
        "rule": "a statement carries fixed + ceil((values - fixed) x n / scale) values at table size n; a route is marked when its largest statement carries more than limit_values_of_the_database",
        "limit_values_of_the_database": LIMIT,
        "limit_is_an_input": "the tool reads it from resources.db_placeholder_limit and has no default; 65,535 is MySQL's limit as the records name it",
        "captures": {
            "bookstack": {"sha256": BOOKSTACK_SHA, "scales": bookstack["scales"], "representative_scale": bookstack["representative_scale"], "kept": "outside the repository"},
            "bookstack_small": {"sha256": BOOKSTACK_SMALL_SHA, "scales": small["scales"], "representative_scale": small["representative_scale"], "kept": "outside the repository", "used_for": "checking the rule only"},
            "fixtures": {"sha256": FIXTURES_SHA, "scales": fixtures["scales"], "representative_scale": fixtures["representative_scale"], "kept": "outside the repository"},
        },
        "statements": stmts,
        "book_page_statement": {
            "route": BOOK_PAGE,
            "id": BOOK_PAGE_STATEMENT,
            "pattern_start": "select * from `activities` where ((`loggable_type` = ? and `loggable_id` in (?)) or ...",
            "values_at_10000_rows": page_small["values"],
            "values_at_30000_rows": page["values"],
            "line_through_the_two": {"per_row": slope, "constant": intercept},
            "rule_from_30000_gives_at_10000": predicted_small,
            "layout_it_agrees_with": "poc/runtime/profiles/bookstack.php sets type by MOD(id, 5): 1 book, 2 chapter, 0 shelf, 3 and 4 page; chapters and pages all sit in book 1. The statement lists the book (1), every chapter of the book (N/5 ids) and every page (2N/5 ids) and has 3 more values for the types: 4 + 0.6 N.",
            "the_log_agrees": "the application's log at 300,000 rows (lab-data/ladder-300k/logs/) lists the chapters as 2, 7, 12, ... in steps of 5",
        },
        "bulk_insert_statement": {
            "route": BULK,
            "values_at_1000_rows": bulk[1000]["values"],
            "values_at_10000_rows": bulk[10000]["values"],
            "rule_from_10000_gives_at_30000": values_at(bulk[10000], 30000),
        },
        "judged_on_the_lab_records": judged(stmts),
    }


def render(doc: dict) -> str:
    return json.dumps(doc, indent=2, ensure_ascii=False) + "\n"


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--bookstack", required=True, type=Path)
    ap.add_argument("--bookstack-small", required=True, type=Path)
    ap.add_argument("--fixtures", required=True, type=Path)
    ap.add_argument("--out", type=Path)
    ap.add_argument("--check", action="store_true")
    a = ap.parse_args(argv)
    doc = build(load(a.bookstack, BOOKSTACK_SHA, "bookstack"), load(a.bookstack_small, BOOKSTACK_SMALL_SHA, "bookstack-small"), load(a.fixtures, FIXTURES_SHA, "fixtures"))
    text = render(doc)
    if a.out is None:
        sys.stdout.write(text)
        return 0
    target = a.out / "rule.json"
    if a.check:
        same = target.is_file() and target.read_text() == text
        print("rule.json is what the script gives" if same else "rule.json differs from what the script gives")
        return 0 if same else 1
    a.out.mkdir(parents=True, exist_ok=True)
    target.write_text(text)
    print(f"wrote {target}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
