#!/usr/bin/env python3
"""Puts the 12 routes of D105/D124 (fitted from the smallest size N=10) into the inputs of the other BookStack routes.

  python3 -I merge_inputs.py --out <dir> \
      --capture <capture.json> --capture12 <capture.json of the 12 routes> \
      --explain <explain.json> --explain12 <explain.json of the 12 routes> \
      --fits <fits.json of the step-1 record> --fits12 <fits.json of the 12-route capture>

Writes capture.json, explain.json and fits.json into --out. Each of the 12 routes of D124 is taken whole from the 12-route files
(capture, EXPLAIN and fits); every other route stays as it is in the first files. A route of the 12 that is missing from one of the
12-route files stops the run (exit 3): nothing is filled in by hand. Reads files only; the same inputs always give the same output."""
import argparse, json, sys

# D124: the denominator routes of D105 (POST /books/{bookSlug}/copy is not one of them).
ROUTES12 = [
    'DELETE /import/{id}', 'DELETE /settings/sorting/rules/{id}', 'GET /books/{bookSlug}/chapter/{chapterSlug}',
    'GET /books/{bookSlug}/copy', 'GET /books/{bookSlug}/export/zip', 'GET /books/{bookSlug}/page/{pageSlug}',
    'GET /books/{slug}', 'GET /settings/sorting/rules/{id}', 'GET /shelves/{slug}',
    'POST /books/{bookSlug}/draft/{pageId}', 'PUT /books/{slug}', 'PUT /settings/sorting/rules/{id}',
]


def load(p):
    with open(p) as f:
        return json.load(f)


def merge(base, extra, label):
    have = {r['route']: r for r in extra['routes']}
    missing = [r for r in ROUTES12 if r not in have]
    if missing:
        sys.stderr.write(f'merge_inputs: {label}: missing from the 12-route file: {missing}\n')
        sys.exit(3)
    kept = [r for r in base['routes'] if r['route'] not in ROUTES12]
    return kept + [have[r] for r in ROUTES12]


def main():
    ap = argparse.ArgumentParser()
    for n in ('out', 'capture', 'capture12', 'explain', 'explain12', 'fits', 'fits12'):
        ap.add_argument('--' + n, required=True)
    a = ap.parse_args()
    cap, exp, fits = load(a.capture), load(a.explain), load(a.fits)
    cap['routes'] = merge(cap, load(a.capture12), 'capture')
    exp['routes'] = merge(exp, load(a.explain12), 'explain')
    fits['routes'] = merge(fits, load(a.fits12), 'fits')
    for name, doc in (('capture.json', cap), ('explain.json', exp), ('fits.json', fits)):
        doc['routes'].sort(key=lambda r: r['route'])
        with open(f'{a.out}/{name}', 'w') as f:
            json.dump(doc, f, indent=1, ensure_ascii=False)
            f.write('\n')
    print(f'merged {len(ROUTES12)} routes; routes: capture {len(cap["routes"])}, explain {len(exp["routes"])}, fits {len(fits["routes"])}')


main()
