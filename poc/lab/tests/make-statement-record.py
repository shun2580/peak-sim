#!/usr/bin/env python3
"""Makes a small, made-up statement record (the shape of poc/runtime/CAPTURE_FORMAT.md) for the tests of the write guards. It reads no database and no
real capture: every route of the lab's route list gets a few select types, and GET /books/{slug} and GET /shelves/{slug} get one write to `views` each.

    make-statement-record.py <route-list.json> <out.json> [--variant=ok|other-table|ddl|join-update|two-statements|incomplete|not-captured|missing-route|schema-qualified|empty|prepared-insert|prepared-delete|prepared-update]
"""
import json
import sys

route_list, out = sys.argv[1], sys.argv[2]
variant = "ok"
for a in sys.argv[3:]:
    if a.startswith("--variant="):
        variant = a[len("--variant="):]

selected = [r["route"] for r in json.load(open(route_list))["selected"]]
SCALES = [100, 1000]


def stype(i, kind, pattern, count=1):
    return {"id": "s%09d" % i, "kind": kind, "pattern": pattern,
            "per_scale": {str(s): {"count": count, "texts": 1, "rows_sum": count, "rows_min": 1, "rows_max": 1, "unread": 0} for s in SCALES}}


routes = []
for n, route in enumerate(selected):
    types = [stype(n * 10 + 1, "select", "select * from `entities` where `id` = ?", 3),
             stype(n * 10 + 2, "select", "select * from `references` where `to_id` = ?", 1)]
    if route == "GET /books/{slug}":
        types.append(stype(n * 10 + 3, "update", "update `views` set `views` = ?, `views`.`updated_at` = ? where `id` = ?"))
    if route == "GET /shelves/{slug}":
        types.append(stype(n * 10 + 3, "insert", "insert into `views` (`user_id`, `views`) values (?, ?)"))
    entry = {"route": route, "status": "captured", "captured_scales": SCALES, "statement_types": types,
             "totals": {str(s): {"statements": sum(t["per_scale"][str(s)]["count"] for t in types), "rows_sum": 0, "unaccounted": 0} for s in SCALES}}
    routes.append(entry)

by = {r["route"]: r for r in routes}
books = by["GET /books/{slug}"]
if variant == "other-table":
    books["statement_types"][-1] = stype(93, "update", "update `entities` set `name` = ? where `id` = ?")
elif variant == "ddl":
    books["statement_types"].append(stype(94, "alter", "alter table `entities` add `x` int"))
    for s in SCALES:
        books["totals"][str(s)]["statements"] += 1
elif variant == "join-update":
    books["statement_types"][-1] = stype(95, "update", "update `views` left join `entities` on `entities`.`id` = `views`.`viewable_id` set `entities`.`name` = ?")
elif variant == "two-statements":
    books["statement_types"][-1] = stype(96, "update", "update `views` set `views` = ?; update `entities` set `name` = ?")
elif variant == "schema-qualified":
    books["statement_types"][-1] = stype(97, "update", "update `other_db`.`views` set `views` = ? where `id` = ?")
elif variant == "prepared-insert":   # a prepared statement: placeholders only, the values are bindings (the digest tables do not count these; the record does)
    books["statement_types"][-1] = stype(91, "insert", "insert into `settings` (`setting_key`, `value`) values (?, ?)")
elif variant == "prepared-delete":
    books["statement_types"][-1] = stype(92, "delete", "delete from `entities` where `id` in (?, ?, ?)")
elif variant == "prepared-update":
    books["statement_types"][-1] = stype(99, "update", "update `entities` set `name` = ? where `id` = ?")
elif variant == "empty":
    books["statement_types"][-1] = stype(98, "update", "")
elif variant == "incomplete":
    books["totals"]["1000"]["statements"] += 5
elif variant == "not-captured":
    books["status"] = "partial"
elif variant == "missing-route":
    routes = [r for r in routes if r["route"] != "GET /books/{slug}"]
elif variant != "ok":
    sys.exit("unknown variant " + variant)

json.dump({"capture_version": 1, "app": "made-up", "scales": SCALES, "routes": routes}, open(out, "w"))
