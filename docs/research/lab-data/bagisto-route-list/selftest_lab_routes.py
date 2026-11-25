#!/usr/bin/env python3
"""Checks of select_lab_routes.py on small made-up captures, with answers known by hand.

    python3 -I selftest_lab_routes.py

Standard library only. Exit 0 when every check passes.
"""
import copy
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import select_lab_routes as m  # noqa: E402

N_TABLES = ["n_table_%03d" % i for i in range(123)]


def stype(kind, pattern, sid="s"):
    return {"id": sid, "kind": kind, "pattern": pattern}


def route(uri, rows_sum, statements=None, unread=0, method="GET", cold=None):
    totals = {m.SCALE: {"statements": 1, "rows_sum": rows_sum, "cold_rows_examined": cold if cold is not None else rows_sum,
                        "unaccounted": 0, "unread_events": unread}}
    return {"route": "%s %s" % (method, uri), "method": method, "uri": uri, "status": "captured",
            "totals": totals,
            "statement_types": statements if statements is not None else [stype("select", "select * from `a` where `id` = ?")]}


def base(n=10):
    routes = [route("/r%02d" % i, 1000 + i) for i in range(n)]
    return {"routes": routes}


def run(capture, arrived=None, state=(), jsonl=None, n_tables=N_TABLES):
    arrived = arrived if arrived is not None else [r["route"] for r in capture["routes"]]
    return m.select(capture, arrived, n_tables, list(state), jsonl or {})


def expect_stop(name, fn, fragment):
    try:
        fn()
    except m.Stop as exc:
        assert fragment in str(exc), "%s: stop text %r lacks %r" % (name, str(exc), fragment)
        return
    raise AssertionError("%s: no stop" % name)


def main():
    # 1. order: largest rows_sum first; ties by "METHOD URI"; top 8 of 10
    cap = base(10)
    cap["routes"][3]["totals"][m.SCALE]["rows_sum"] = 1009  # ties with /r09
    rows, excluded, kept, chosen, allowed, notget = run(cap)
    got = [x["route"] for x in chosen]
    assert len(got) == 8, got
    assert got[:2] == ["GET /r03", "GET /r09"], got  # same sum 1009: "GET /r03" sorts before "GET /r09"
    assert got[2] == "GET /r08", got
    assert "GET /r00" not in got and "GET /r01" not in got

    # 2. fewer than 8 candidates: all of them; exactly 6 is enough
    _, _, _, chosen, _, _ = run(base(6))
    assert len(chosen) == 6
    _, _, _, chosen, _, _ = run(base(7))
    assert len(chosen) == 7

    # 3. fewer than 6 stops
    expect_stop("five", lambda: run(base(5)), "fewer than 6")

    # 4. a listed route that is not GET is not a candidate
    cap = base(9)
    cap["routes"][0] = route("/r00", 5000, method="POST")
    rows, _, _, chosen, _, notget = run(cap)
    assert notget == ["POST /r00"] and len(rows) == 8 and len(chosen) == 8

    # 5. stops on a missing, null or unread 30000 entry
    cap = base(8)
    del cap["routes"][2]["totals"][m.SCALE]
    expect_stop("missing 30000", lambda: run(cap), "no totals")
    cap = base(8)
    cap["routes"][2]["totals"][m.SCALE]["rows_sum"] = None
    expect_stop("null rows_sum", lambda: run(cap), "null")
    cap = base(8)
    cap["routes"][2]["totals"][m.SCALE]["unread_events"] = 1
    expect_stop("unread", lambda: run(cap), "unread_events")

    # 6. a listed route that the capture lacks stops
    expect_stop("not in capture", lambda: run(base(8), arrived=["GET /r00", "GET /nope"]), "not in the capture")

    # 7. state_changing among the candidates stops
    expect_stop("state_changing", lambda: run(base(8), state=["GET /r01"]), "state_changing")

    # 8. write guard: a route writing an N table is taken out and the next one comes in
    cap = base(9)
    cap["routes"][8]["statement_types"].append(stype("update", "update `n_table_007` set `x` = ? where `id` = ?"))
    rows, excluded, kept, chosen, allowed, _ = run(cap)
    assert [x["route"] for x in excluded] == ["GET /r08"]
    assert "GET /r08" not in [x["route"] for x in chosen] and len(chosen) == 8
    assert excluded[0]["in_n"] == ["n_table_007"]

    # 9. a write to another table is allowed by table, with its kinds and routes
    cap = base(8)
    cap["routes"][7]["statement_types"].append(stype("insert", "insert into `visits` (`a`) values (?)"))
    cap["routes"][6]["statement_types"].append(stype("update", "update `visits` set `n` = ? where `id` = ?"))
    cap["routes"][5]["statement_types"].append(stype("delete", "delete from `sessions` where `last` < ?"))
    _, excluded, _, chosen, allowed, _ = run(cap)
    assert not excluded
    assert sorted(allowed) == ["sessions", "visits"], allowed
    assert allowed["visits"]["kinds"] == {"insert", "update"}
    assert sorted(allowed["visits"]["routes"]) == ["GET /r06", "GET /r07"]

    # 10. a write of empty kind, an unknown kind, an unreadable pattern: stop
    for label, st, frag in [
        ("empty kind write", stype("", "/* x */ delete from `visits` where `id` = ?"), "empty kind"),
        ("unknown kind", stype("truncate", "truncate table `visits`"), "does not cover"),
        ("multi-table delete", stype("delete", "delete `a` from `a` inner join `b` on `a`.`id` = `b`.`id`"), "delete"),
        ("update with subquery", stype("update", "update (select 1) as t set `x` = ?"), "update"),
        ("with that writes", stype("with", "with `c` as (select 1) delete from `visits`"), "WITH"),
    ]:
        cap = base(8)
        cap["routes"][4]["statement_types"].append(st)
        expect_stop(label, lambda cap=cap: run(cap), frag)

    # 11. an empty-kind statement without a write word is read as not writing and is noted
    cap = base(8)
    cap["routes"][4]["statement_types"].append(stype("", "/* x */ select 1"))
    rows, _, _, _, _, _ = run(cap)
    assert any(x["notes"] for x in rows)

    # 12. table-name readings
    cases = [
        ("insert", "insert into `db`.`Visits` (`a`) values (?)", ["visits"]),
        ("insert", "insert ignore into visits (a) values (?)", ["visits"]),
        ("replace", "replace into `t1` values (?)", ["t1"]),
        ("update", "update `a` inner join `b` on `a`.`id` = `b`.`a_id` set `a`.`x` = ?", ["a", "b"]),
        ("update", "update `a`, `b` set `a`.`x` = `b`.`y`", ["a", "b"]),
        ("delete", "delete from `a` where `id` in (select `id` from `b`)", ["a"]),
    ]
    for kind, sql, want in cases:
        assert m.tables_written(kind, sql) == want, (sql, m.tables_written(kind, sql))

    # 13. the N list must have 123 distinct names
    expect_stop("n list short", lambda: run(base(8), n_tables=N_TABLES[:5]), "not 123")
    expect_stop("n list repeat", lambda: run(base(8), n_tables=N_TABLES[:122] + [N_TABLES[0]]), "repeated")

    # 14. a full run writes the files, and a second run gives the same files
    cap = base(10)
    with tempfile.TemporaryDirectory() as tmp:
        outs = []
        for i in range(2):
            out = os.path.join(tmp, "o%d" % i)
            result = m.select(cap, [r["route"] for r in cap["routes"]], N_TABLES, [], {})
            m.write_outputs(out, *result, [("x", "0")])
            outs.append({f: open(os.path.join(out, f), encoding="utf-8").read() for f in sorted(os.listdir(out))})
        assert outs[0] == outs[1]
        assert sorted(outs[0]) == ["allowed-tables.tsv", "candidates.tsv", "excluded.tsv", "inputs.sha256",
                                   "reference-orders.tsv", "selected.tsv", "summary.txt"]
        sel = outs[0]["selected.tsv"].splitlines()
        assert sel[0].split("\t") == ["number", "route", "rows_sum_30000"] and len(sel) == 9
        assert sel[1].split("\t")[:2] == ["1", "GET /r09"]

    # 15. the reference orders change nothing in the selection
    cap2 = copy.deepcopy(base(10))
    for i, r in enumerate(cap2["routes"]):
        r["totals"][m.SCALE]["cold_rows_examined"] = 9000 - i
    _, _, _, c1, _, _ = run(base(10))
    _, _, _, c2, _, _ = run(cap2)
    assert [x["route"] for x in c1] == [x["route"] for x in c2]

    # 16. the command line, end to end: routes.jsonl is keyed by method and uri apart
    import json
    cap = base(10)
    with tempfile.TemporaryDirectory() as tmp:
        cdir = os.path.join(tmp, "cap")
        os.makedirs(cdir)
        with open(os.path.join(cdir, "capture.json"), "w", encoding="utf-8") as fh:
            json.dump(cap, fh)
        with open(os.path.join(cdir, "routes.jsonl"), "w", encoding="utf-8") as fh:
            for i, r in enumerate(cap["routes"]):
                fh.write(json.dumps({"route": r["uri"], "method": "GET",
                                     "rows": {"scales": [100, 30000], "examined": [1, 7000 + i]}}) + "\n")
        files = {"arrived": "\n".join(r["route"] for r in cap["routes"]) + "\n",
                 "n": "\n".join(N_TABLES),
                 "profile": "<?php\n$profile['state_changing'] = [\n    [\n        'route' => 'POST /x',\n    ],\n];\n\n"}
        for k, v in files.items():
            with open(os.path.join(tmp, k), "w", encoding="utf-8") as fh:
                fh.write(v)
        out = os.path.join(tmp, "out")
        args = ["--capture-dir", cdir, "--arrived-list", os.path.join(tmp, "arrived"),
                "--n-tables", os.path.join(tmp, "n"), "--profile", os.path.join(tmp, "profile"), "--out", out]
        assert m.main(args) == 0
        ref = open(os.path.join(out, "reference-orders.tsv"), encoding="utf-8").read().splitlines()
        assert ref[1].split("\t")[3] == "1" and ref[1].split("\t")[5] == "7009", ref[1]
        assert m.main(args) == 4  # the output directory is not empty now

    print("ok: 16 groups of checks passed, 0 skipped")
    return 0


if __name__ == "__main__":
    sys.exit(main())
