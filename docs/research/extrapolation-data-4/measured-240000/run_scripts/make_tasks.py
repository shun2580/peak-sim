#!/usr/bin/env python3
# Test A at 240,000 rows: the task list of the BookStack repeat (file step 7) and the list of marked routes, made from records only (no Docker, no request).
# Same idea as _out/run-271/variance/make_merged_tasks.py: the union of the two capture task lists, entries copied unchanged, order = the
# first list then the entries of the second that the first lacks. Here the fifth round retaken captures (run-286_t2r, sha256 ee329bfb.. and 92fc9101..) are used (the capture tool 6ae5195 and the
# measure tool differ in nothing that builds the task list; see prep_diffs.txt).
import json, hashlib, os, sys
p = os.path.expanduser
B = p('~/projects/peak-sim-trials/_out/run-286_t2r')
A = B + '/bookstack/tasks.json'
C = B + '/bookstack12/tasks.json'
PRED = p('~/projects/peak-sim-trials/_scratch/run-286-t5a/rep-7a4cf32/poc/lab/predictions')
OUT = os.path.expanduser('~/projects/peak-sim-trials/_out/run-286_t5a/run/tasks_merged_bookstack.json')
TSV = os.path.expanduser('~/projects/peak-sim-trials/_out/run-286_t5a/run/marked_routes.tsv')
key = lambda x: f"{x['spec']['method']} {x['spec']['uri']}"
a = json.load(open(A)); b = json.load(open(C))
seen = {key(x): x for x in a}
out = list(a)
for x in b:
    k = key(x)
    if k in seen:
        assert seen[k] == x, f"overlap differs: {k}"
    else:
        seen[k] = x; out.append(x)
json.dump(out, open(OUT, 'w'), indent=4, ensure_ascii=False)
assert len({key(x) for x in out}) == len(out)
assert json.load(open(OUT)) == out
print('entries', len(out), '(first list', len(a), ', second list', len(b), ', added from the second', len(out) - len(a), ')')
print('sha256', hashlib.sha256(open(OUT, 'rb').read()).hexdigest())
have = {key(x) for x in out}
lines = []
for app in ('fixtures', 'bookstack'):
    m = json.load(open(f'{PRED}/extrapolation-4-{app}-marked.json'))
    assert m['app'] == app and m['at'] == 240000 and m['marked_count'] == len(m['routes'])
    for r in m['routes']:
        lines.append(f"{app}\t{r['route']}")
    print(app, 'marked', m['marked_count'], 'of', m['routes_in_explain'])
bs = [l.split('\t')[1] for l in lines if l.startswith('bookstack\t')]
print('bookstack marked all in the task list:', all(r in have for r in bs))
assert all(r in have for r in bs)
assert not any(',' in r for r in bs), 'a route with a comma cannot go into --routes'
open(TSV, 'w').write(''.join(l + '\n' for l in lines))
print('marked_routes.tsv lines', len(lines), 'sha256', hashlib.sha256(open(TSV, 'rb').read()).hexdigest())
