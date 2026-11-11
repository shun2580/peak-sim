#!/usr/bin/env python3
"""Split the route-level Handler_read_* counters of the per-request records into scan and index reads,
and fit the DB CPU time of the two heavy routes with the current two-coefficient formula
(CPU = c_q * Q + c_row * rows) and with two three-coefficient alternatives
(Q + scan + index, and Q + key + rest). Prints coefficients, fit errors and leave-one-out errors.

Reads only the committed records under docs/research/lab-data/diagnosis/ (per-request/ and zip-limit/);
it does not use the lab. Usage: python3 -I poc/lab/diagnosis/read_kinds_fit.py
Needs numpy.
"""
import itertools
import json
import statistics as st
from pathlib import Path

import numpy as np

DATA = Path(__file__).resolve().parents[3] / 'docs' / 'research' / 'lab-data' / 'diagnosis'
HEAVY_ROUTES = ('/books/{slug}', '/books/{bookSlug}/export/zip')
SOURCES = {
    '10000': 'per-request/10000/http.jsonl',
    '30000': 'per-request/30000/http.jsonl',
    '100000': 'per-request/100000/http.jsonl',
    'zip-unlimited-100000': 'zip-limit/unlimited-100000/http.jsonl',
}
MODELS = {
    'M2 (Q, rows)': ['Q', 'rows'],
    'M3a (Q, scan, idx)': ['Q', 'scan', 'idx'],
    'M3b (Q, key, rest)': ['Q', 'key', 'rest'],
}


def nnls(a, y):
    """Non-negative least squares by trying every subset of columns (few columns only)."""
    n = a.shape[1]
    best = None
    for k in range(1, n + 1):
        for cols in itertools.combinations(range(n), k):
            coef, *_ = np.linalg.lstsq(a[:, cols], y, rcond=None)
            if (coef < 0).any():
                continue
            full = np.zeros(n)
            full[list(cols)] = coef
            resid = ((a @ full - y) ** 2).sum()
            if best is None or resid < best[0]:
                best = (resid, full)
    return best[1]


def cold_requests(path):
    with open(DATA / path) as f:
        return [json.loads(line) for line in f if '"phase": "cold"' in line and '"kind": "request"' in line]


def points():
    pts = []
    for size, path in SOURCES.items():
        by_route = {}
        for r in cold_requests(path):
            if r['net']:
                by_route.setdefault(r['route'], []).append(r)
        for route, rs in by_route.items():
            name = route.split(' ', 1)[1]
            if name not in HEAVY_ROUTES:
                continue

            def med(key):
                return st.median(r['net']['counters_net'].get(key, 0) for r in rs)

            pts.append({
                'size': size, 'route': name, 'Q': med('Questions'),
                'scan': med('Handler_read_rnd_next') + med('Handler_read_first'),
                'key': med('Handler_read_key'),
                'nxt': med('Handler_read_next') + med('Handler_read_prev') + med('Handler_read_last'),
                'rnd': med('Handler_read_rnd'),
                'cpu': st.median(r['net']['cpu_net_usec'] for r in rs) / 1e6,
            })
    # the capped ZIP request at 100,000 rows does less work, so only the uncapped one is used
    pts = [p for p in pts if not (p['size'] == '100000' and 'zip' in p['route'])]
    for p in pts:
        p['idx'] = p['key'] + p['nxt'] + p['rnd']
        p['rows'] = p['scan'] + p['idx']
        p['rest'] = p['nxt'] + p['rnd'] + p['scan']
    return pts


def main():
    pts = points()
    y = np.array([p['cpu'] for p in pts])
    w = 1 / y  # weight by relative error
    for label, names in MODELS.items():
        a = np.array([[p[n] for n in names] for p in pts], float)
        coef = nnls(a * w[:, None], y * w)
        print(label, {n: f'{c:.3e}' for n, c in zip(names, coef)})
        print('  fit error %:', [f"{p['size']}:{p['route']}:{(pr / p['cpu'] - 1) * 100:+.1f}"
                                 for p, pr in zip(pts, a @ coef)])
        loo = []
        for i in range(len(pts)):
            keep = [j for j in range(len(pts)) if j != i]
            cf = nnls(a[keep] * w[keep][:, None], y[keep] * w[keep])
            loo.append((a[i] @ cf / y[i] - 1) * 100)
        print('  leave-one-out error %:', [f'{e:+.1f}' for e in loo])


if __name__ == '__main__':
    main()
