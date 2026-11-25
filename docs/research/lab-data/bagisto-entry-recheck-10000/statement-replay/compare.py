"""Sets the statements one request sent in the lab (route-N.jsonl, from replay.php) beside the capture's statement types for the same route
at 10,000 rows. Usage: python3 -I compare.py <capture.json> <directory with route-1..8.jsonl>. Prints, per route, the statements the lab sent that the
capture's count does not hold (EXTRA, with the phase they were sent in) and the capture's that the lab did not send (MISSING)."""
import collections, json, re, sys

NAMES = ["GET /api/products", "GET /customer/account/addresses/create", "GET /admin/customers/view/{id}",
         "GET /admin/marketing/communications/campaigns/edit/{id}", "GET /admin/settings/inventory-sources/edit/{id}",
         "GET /admin/customers/search", "GET /api/categories", "GET /api/categories/attributes/{attribute_id}/options"]


def norm(s):
    s = re.sub(r"\s+", " ", s.strip().lower())
    s = re.sub(r"in \([^)]*\)", "in (?)", s)
    s = re.sub(r"limit \d+", "limit ?", s)
    return s.replace(" offset ?", "")


cap = {r["route"]: r for r in json.load(open(sys.argv[1]))["routes"]}
for i, name in enumerate(NAMES, 1):
    held = collections.Counter()
    for s in cap[name]["statement_types"]:
        held[norm(s["pattern"])] += s["per_scale"]["10000"]["count"]
    sent = [json.loads(l) for l in open("%s/route-%d.jsonl" % (sys.argv[2], i))]
    sent = [d for d in sent if d["phase"] != "end"]
    seen, phase_of = collections.Counter(), collections.defaultdict(collections.Counter)
    for d in sent:
        k = norm(d["sql"]); seen[k] += 1; phase_of[k][d["phase"]] += 1
    extra, missing = seen - held, held - seen
    print("\n### %d %s: lab %d, capture %d, extra %d, missing %d" % (i, name, len(sent), sum(held.values()), sum(extra.values()), sum(missing.values())))
    for k, c in sorted(extra.items(), key=lambda x: -x[1]):
        print("  EXTRA +%d [%s] %s" % (c, ",".join("%s:%d" % kv for kv in phase_of[k].items()), k[:110]))
    for k, c in sorted(missing.items(), key=lambda x: -x[1]):
        print("  MISSING -%d %s" % (c, k[:110]))
