#!/usr/bin/env python3
"""Which routes have a peak-memory formula that held within +-30% in the extrapolation tests.

Reads only the records of the two extrapolation tests and writes one `memory-accuracy.json` per app, the file the simulator reads for
its PHP-memory line (simulator-spec.md section 6.2). Nothing is typed in by hand: the same inputs give the same files.

  test 1 (100,000 rows):  the list of routes within +-30% in docs/research/extrapolation-test.md, and the routes that had a peak-memory
                          formula (extrapolation-data/<app>/fits.json). A route with a formula that is not in the list and was
                          measured is outside; the route the document names as not measurable at 100,000 rows is not counted.
  test 2 (200,000 rows):  compare.json of this directory. A route is within when both rule A and rule B put its peak memory within
                          the lowest tolerance; it is outside when it was measured and either rule did not.

A route is `within` when it was within in every test that measured it (and at least one did); `outside` when any test measured it
outside; in neither list when no test measured it.

  python3 -I memory_accuracy.py --out <dir>     writes <dir>/<app>/memory-accuracy.json for the apps `fixtures` and `bookstack`
"""
import argparse
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
RESEARCH = os.path.dirname(HERE)
APPS = {  # app of compare.json -> (label in test 1's document, directory of test 1's fits)
    "fixtures": ("Verification app", "fixtures"),
    "bookstack": ("BookStack", "bookstack"),
}


def test1_within(doc_text, label):
    m = re.search(r"^- " + re.escape(label) + r" \((\d+) routes\): (.+)$", doc_text, re.M)
    if not m:
        sys.exit("test 1 document: no list of routes within +-30% for " + label)
    routes = re.findall(r"`([^`]+)`", m.group(2))
    if len(routes) != int(m.group(1)):
        sys.exit("test 1 document: %s says %s routes but lists %d" % (label, m.group(1), len(routes)))
    return set(routes)


def test1_unmeasured(doc_text):
    """The routes of a formula the document says could not be measured at 100,000 rows (one app's paragraph names them)."""
    out = set()
    for m in re.finditer(r"could not be measured at 100,000: \*\*\d+\*\* \(`([^`]+)`", doc_text):
        out.add(m.group(1))
    return out


def with_peak_memory_formula(fits_path):
    with open(fits_path) as f:
        fits = json.load(f)
    return {
        r["route"]
        for r in fits["routes"]
        if r["quantities"]["peak_memory"].get("fit_status") == "ok"
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", required=True)
    args = ap.parse_args()

    with open(os.path.join(RESEARCH, "extrapolation-test.md")) as f:
        doc1 = f.read()
    with open(os.path.join(HERE, "compare.json")) as f:
        compare = json.load(f)
    unmeasured1 = test1_unmeasured(doc1)

    for entry in compare["apps"]:
        app = entry["app"]
        if app not in APPS:
            continue
        label, fits_dir = APPS[app]
        formulas1 = with_peak_memory_formula(os.path.join(RESEARCH, "extrapolation-data", fits_dir, "fits.json"))
        within1 = test1_within(doc1, label)
        if not within1 <= formulas1:
            sys.exit("test 1: routes within +-30% without a peak-memory formula: " + ", ".join(sorted(within1 - formulas1)))
        measured1 = formulas1 - (unmeasured1 & formulas1)
        outside1 = measured1 - within1

        within2, outside2 = set(), set()
        for r in entry["rows"]:
            pm = r["q"]["peak_memory"]
            if pm.get("category") != "in_denominator":
                continue
            ok = all(pm[rule]["within_min"] for rule in ("A", "B"))
            (within2 if ok else outside2).add(r["route"])

        outside = outside1 | outside2
        within = (within1 | within2) - outside
        doc = {
            "schema_version": "1",
            "source": "peak memory within +-30% in the first extrapolation test (100,000 rows) and the second (200,000 rows); see memory_accuracy.py",
            "tolerance": 0.3,
            "app": app,
            "within": sorted(within),
            "outside": sorted(outside),
            "tests": {
                "first": {"measured": len(measured1), "within": len(within1), "outside": len(outside1)},
                "second": {"measured": len(within2) + len(outside2), "within": len(within2), "outside": len(outside2)},
            },
        }
        target = os.path.join(args.out, app)
        os.makedirs(target, exist_ok=True)
        with open(os.path.join(target, "memory-accuracy.json"), "w") as f:
            json.dump(doc, f, indent=2, sort_keys=False)
            f.write("\n")
        print("%s: %d within, %d outside (first test %d/%d, second test %d/%d)" % (
            app, len(within), len(outside), len(within1), len(measured1), len(within2), len(within2) + len(outside2)))


if __name__ == "__main__":
    main()
