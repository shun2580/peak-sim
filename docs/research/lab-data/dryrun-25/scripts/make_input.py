#!/usr/bin/env python3
"""Makes the scenario file of the rehearsal prediction: the eight calibration runs as the calibration tool wrote them, and the five target
scenarios s-1 .. s-5 at the rehearsal's count. Nothing is typed by hand but the count and the five mixes (the shares of section 7.4.2).

    python3 -I make_input.py <calibration.yaml> <route-list.json> <count> <out.yaml>

The calibration part is copied line by line (the `scenarios:` entries of the calibration file, unchanged). The resources are the ones of the earlier
lab backtests: two database cores, the 80% line, the memory limit of 4G and the 65,535 values in one statement.
"""
import json
import re
import sys

cal_path, routes_path, count, out_path = sys.argv[1], sys.argv[2], int(sys.argv[3]), sys.argv[4]
routes = [r["route"] for r in json.load(open(routes_path))["selected"]]
n = len(routes)
assert n == 8, n


def shares(places80):
    """The places in places80 (1-based) share 0.8 evenly; the rest share 0.2 evenly."""
    hi = 0.8 / len(places80)
    lo = 0.2 / (n - len(places80))
    return [hi if (i + 1) in places80 else lo for i in range(n)]


mixes = [
    ("s-1", "even", [1.0 / n] * n),
    ("s-2", "first80", shares([1, 2, 3, 4])),
    ("s-3", "last80", shares([5, 6, 7, 8])),
    ("s-4", "places1235", shares([1, 2, 3, 5])),
    ("s-5", "places1345", shares([1, 3, 4, 5])),
]

text = open(cal_path, encoding="utf-8").read()
head, rest = text.split("scenarios:\n", 1)
head = re.sub(r"\nresources:\n  db_cpu:\n    cores: 2\n", "\nresources:\n  db_cpu:\n    cores: 2\n    threshold: 0.8\n  app_memory_limit: 4G\n  db_placeholder_limit: 65535\n", head)
assert "db_placeholder_limit" in head
out = [head.rstrip("\n") + "\n", "scenarios:\n", rest.rstrip("\n") + "\n"]
for sid, name, sh in mixes:
    out.append("\n  - id: %s\n    # mix of routes: %s, total 1.0 rps (the stages rescale it)\n    kind: target\n    tables: { n_shared: %d }\n    rps:\n" % (sid, name, count))
    for r, s in zip(routes, sh):
        out.append('      "%s": %s\n' % (r, repr(round(s, 12))))
with open(out_path, "w", encoding="utf-8") as f:
    f.write("".join(out))
