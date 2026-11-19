#!/usr/bin/env python3
"""Reads the records of p4.py and prints, per state and route: the totals, the statements that read the rows (tapped run), the plan
of each large statement, and the cross-check against the server's digest counters (warm-up + cold minus warm-up)."""
import json, os, sys

OUT = os.path.expanduser("~/.cache/peak-lab/out/p4-300k")

def short(sql, n=110):
    s = " ".join(sql.split())
    return s if len(s) <= n else s[:n] + "…"

def plan_str(ex):
    if not ex or not ex.get("ok"):
        return "(no plan)"
    parts = []
    for r in ex["rows"]:
        parts.append("%s:%s key=%s rows=%s" % (r["table"], r["type"], r["key"], r["rows"]))
    return " | ".join(parts)

def tap_table(label, route, top=8):
    p = os.path.join(OUT, label, route + ".tap.json")
    if not os.path.exists(p):
        print("  (no tap file)")
        return
    d = json.load(open(p))
    w = d["window"]
    print("  tap: status %s class %s; window: cold_rows_examined %s statement_events %s rows_sum %s unaccounted %s" % (
        d["status"], d["class"], w["cold_rows_examined"], w["statement_events"], w["rows_sum"], w["unaccounted"]))
    sts = sorted(d["statements"], key=lambda s: -s["rows_sum"])
    for s in sts[:top]:
        print("   %9d rows  x%-4d (%s)  %s" % (s["rows_sum"], s["count"], s["type"], short(s["sql"])))
        if s["rows_sum"] >= 1000:
            print("       first plan: " + plan_str(s.get("explain_first")))
            if s.get("explain_last") != s.get("explain_first"):
                print("       last  plan: " + plan_str(s.get("explain_last")))

def digest_cross(label, route):
    dw = os.path.join(OUT, label, route + ".w.counters.json")
    dwc = os.path.join(OUT, label, route + ".wc.counters.json")
    if not (os.path.exists(dw) and os.path.exists(dwc)):
        return
    w = json.load(open(dw)); wc = json.load(open(dwc))
    def key(x): return (x["digest"], x["schema"])
    wd = {key(x): x for x in w["digest_delta"]}
    cold = {}
    for x in wc["digest_delta"]:
        k = key(x)
        b = wd.get(k, {"d_count": 0, "d_rows_examined": 0})
        c, e = x["d_count"] - b["d_count"], x["d_rows_examined"] - b["d_rows_examined"]
        if c or e:
            cold[k] = (x["text"], c, e)
    tot_c = sum(v[1] for v in cold.values()); tot_e = sum(v[2] for v in cold.values())
    print("  digest counters, cold = (warm-up + cold) - (warm-up): %d statements, %d rows examined (all schemas)" % (tot_c, tot_e))
    for k, v in sorted(cold.items(), key=lambda kv: -kv[1][2])[:6]:
        print("   %9d rows  x%-4d %s" % (v[2], v[1], short(v[0])))
    print("  attempts (warm-up+cold run):", [(a["attempt"], a["queries"], a["rows_examined"]) for a in wc["attempts"]],
          "| (warm-up run):", [(a["attempt"], a["queries"], a["rows_examined"]) for a in w["attempts"]])
    print("  server Handler_read_* delta, warm-up+cold run minus warm-up run:",
          {k: wc["global_status_delta"][k] - w["global_status_delta"][k] for k in wc["global_status_delta"] if k.startswith("Handler_read")})

def main(labels):
    for label in labels:
        print("=== %s" % label)
        for tsv in ("stats-before.tsv",):
            p = os.path.join(OUT, label, tsv)
            if os.path.exists(p):
                for l in open(p):
                    if l.split("\t")[0] in ("references", "entities", "views"):
                        print("  stats", l.strip())
        for route in ("shelf", "page"):
            print(" -- %s" % route)
            tap_table(label, route)
            digest_cross(label, route)

if __name__ == "__main__":
    main(sys.argv[1:])
