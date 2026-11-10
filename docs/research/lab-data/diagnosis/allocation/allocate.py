"""Allocation of the lab ladder's miss (predicted minus measured utilisation) to three causes.

A reading of records that already exist. It runs no measurement, calls nothing in the lab, and changes no prediction, estimate,
calibration, formula or score. It adds no number of its own: every input is read from a committed record, and the coefficients are
the ones in the committed prediction.

    python3 -I allocate.py            (run from any directory; writes allocation.json and allocation-tables.md beside itself)

Inputs (all under docs/research/lab-data/):
  poc/lab/predictions/ladder-prediction.json   committed prediction: the stage rps of every route, the point utilisation U0, c_q, c_row
  score/score.json                             which stages are usable (valid rps, measured utilisation 10-90%) and the measured utilisation M
  ladder/<scenario>/ladder.json                decided and achieved rps, HTTP statuses and requests per route of every stage
  diagnosis/per-request/<N>/table.json         one request, route by route: the estimate, the measured statements and rows, the database CPU time
  diagnosis/zip-limit/compare.json             the ZIP route at 100,000 rows with php's execution limit (cut) and without it (uncut)
  diagnosis/no-response/status0-by-route.csv   requests with no response (HTTP status 0) by route and stage

Definitions (fixed before the per-request and ZIP records were read):
  stage j usable as in the score; lambda_rj = the committed prediction's rps of route r at stage j; M_j = measured utilisation
  k_r^est  = c_q x statements + c_row x rows, the committed estimate at the scenario's row count (point reading)
  k_r^full = the same with the measured statements and rows (the cold request of measure-one)
             ZIP at 100,000 rows: the server's counters over the HTTP window of the request WITHOUT php's limit (zip-limit/)
  k_zip^trunc = the same with the server's counters over the HTTP window of the request WITH the limit (per-request/100000/)
  f_j      = share of the stage's ZIP requests that did not return 200 (HTTP 500 and no response), 100,000 rows only
  k_zip,j^run = f_j k^trunc + (1 - f_j) k^full
  U0 = sum lambda k^est / cores          (equals the committed point utilisation: checked)
  U1 = sum lambda k^full / cores
  U2 = U1 with ZIP at k_zip,j^run
  U3 = U2 with k^full and k^trunc replaced by the measured database CPU time of one request (k^full where that time is withheld)
  (a) = U0 - U1   (b) = U1 - U2   (c) = U2 - M      delta = U0 - M = (a) + (b) + (c)
  (c1) = U2 - U3  (c2) = U3 - M   (c1) + (c2) = (c)
  (c3) = U2 at the achieved rps minus U2 at the decided rps (all routes scaled alike); it is inside (c2), not added to it
  other order: (b'') = U0 - U1', (a'') = U1' - U2, U1' = U0 with ZIP at k_zip^est x k_zip,j^run / k_zip^full
  aligned (a): the estimate of every route's statements and rows divided by (ratio at N) / (ratio at 10,000), ratio = estimate / measured;
               where a ratio does not exist the measured value is put in, as in U1
"""

import csv
import glob
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
LAB = os.path.normpath(os.path.join(HERE, '..', '..'))
ROOT = os.path.normpath(os.path.join(LAB, '..', '..', '..'))
ZIP = 'GET /books/{bookSlug}/export/zip'
SCALES = (10000, 30000, 100000)


def load(path):
    with open(path, encoding='utf-8') as fh:
        return json.load(fh)


pred = load(os.path.join(ROOT, 'poc', 'lab', 'predictions', 'ladder-prediction.json'))
score = load(os.path.join(LAB, 'score', 'score.json'))
zlim = load(os.path.join(LAB, 'diagnosis', 'zip-limit', 'compare.json'))
CQ = float(pred['calibration']['coefficients']['c_q'])
CR = float(pred['calibration']['coefficients']['c_row'])
CORES = float(pred['resources']['cores'])

status0 = {}
with open(os.path.join(LAB, 'diagnosis', 'no-response', 'status0-by-route.csv'), encoding='utf-8') as fh:
    for row in csv.DictReader(fh):
        status0[(row['scenario'], int(row['step']), row['route'])] = int(row['requests_with_no_response'])

# ---- per route and row count: estimate, measured counts, measured database CPU -------------------------------------------------
route_data = {}   # (scale, route) -> dict
idle_points = {}  # scale -> the database's idle CPU rate read in the per-request record, in points of the 2-core utilisation
for n in SCALES:
    table = load(os.path.join(LAB, 'diagnosis', 'per-request', str(n), 'table.json'))
    idle_points[n] = float(table['idle_rate_usec_per_s']) / 1e6 / CORES
    for r in table['routes']:
        e = r['estimate']
        withheld = bool(r['cpu_below_measurement_floor']) or bool(r['cpu_readings_scatter_more_than_median'])
        route_data[(n, r['route'])] = {
            'q_est': float(e['queries']), 'r_est': float(e['rows']),
            'q_full': float(r['measured_queries']), 'r_full': float(r['measured_rows']),
            'cpu': float(r['cpu_median_s']), 'cpu_withheld': withheld,
        }
# ZIP at 100,000 rows: both measures from the HTTP windows (server counters), cut and uncut
zc, zu = zlim['cut'], zlim['uncut']
zd = route_data[(100000, ZIP)]
zd.update({
    'q_trunc': zc['questions']['median'], 'r_trunc': zc['handler_reads']['median'],
    'q_full': zu['questions']['median'], 'r_full': zu['handler_reads']['median'],
    'cpu_trunc': zc['db_cpu_net_s']['median'], 'cpu': zu['db_cpu_net_s']['median'], 'cpu_withheld': False,
})
ROUTES = sorted({r for (_, r) in route_data})


def k(q, rows):
    return CQ * q + CR * rows


def k_est(n, r):
    d = route_data[(n, r)]
    return k(d['q_est'], d['r_est'])


def k_full(n, r):
    d = route_data[(n, r)]
    return k(d['q_full'], d['r_full'])


def k_trunc(n, r):
    d = route_data[(n, r)]
    return k(d['q_trunc'], d['r_trunc']) if 'q_trunc' in d else k_full(n, r)


def cpu_full(n, r):
    d = route_data[(n, r)]
    return k_full(n, r) if d['cpu_withheld'] else d['cpu']


def cpu_trunc(n, r):
    d = route_data[(n, r)]
    return d['cpu_trunc'] if 'cpu_trunc' in d else cpu_full(n, r)


def aligned_k(n, r):
    """Estimate with its bias at 10,000 rows kept and the growth of the bias with the row count taken out: the measured value
    at N times the estimate / measured ratio at 10,000 rows, statements and rows each; the measured value where a ratio is not defined."""
    d, d0 = route_data[(n, r)], route_data[(10000, r)]
    out = []
    for est, meas, est0, meas0 in ((d['q_est'], d['q_full'], d0['q_est'], d0['q_full']), (d['r_est'], d['r_full'], d0['r_est'], d0['r_full'])):
        if est > 0 and meas > 0 and est0 > 0 and meas0 > 0:
            out.append(meas * (est0 / meas0))      # = est / ((est/meas at N) / (est/meas at 10,000))
        else:
            out.append(meas)
    return k(out[0], out[1])


# ---- stages ----------------------------------------------------------------------------------------------------------------------
committed = {s['id']: s for s in pred['scenarios']}
result = {'schema_version': '0.1', 'kind': 'allocation-of-the-ladder-miss', 'c_q': CQ, 'c_row': CR, 'cores': CORES, 'scenarios': []}
max_u0_diff = 0.0

for sc in score['scenarios']:
    sid = sc['id']
    ladder_dir = glob.glob(os.path.join(LAB, 'ladder', sid + '-*'))[0]
    lad = load(os.path.join(ladder_dir, 'ladder.json'))
    n = int(lad['scale'])
    scen_key = '%s-%d' % (sid, n)
    stages = []
    for i, (st, ru) in enumerate(zip(sc['stages'], lad['rungs'])):
        if not st['usable']:
            continue
        cs = committed[sid]['stages'][i]
        lam = {r: float(v) for r, v in cs['rps'].items()}
        u0_commit = float(cs['utilization']['point'])
        u0 = sum(lam[r] * k_est(n, r) for r in ROUTES) / CORES
        max_u0_diff = max(max_u0_diff, abs(u0 - u0_commit))
        m = float(st['observed'])
        pz = [p for p in ru['per_route'] if p['route'] == ZIP][0]
        non200 = int(ru['http_status'].get('500', 0)) + status0.get((scen_key, int(ru['rung']), ZIP), 0)
        f = 0.0 if n < 100000 else min(1.0, non200 / pz['requests'])
        lam_zip = lam[ZIP]

        def total(fn, zip_fn=None):
            s = 0.0
            for r in ROUTES:
                s += lam[r] * (zip_fn(n, r) if (zip_fn and r == ZIP) else fn(n, r))
            return s / CORES

        def run(fn_full, fn_trunc):
            return lambda nn, r: f * fn_trunc(nn, r) + (1 - f) * fn_full(nn, r)

        u1 = total(k_full)
        u2 = total(k_full, run(k_full, k_trunc))
        u3 = total(cpu_full, run(cpu_full, cpu_trunc))
        # other order
        zip_scale = (f * k_trunc(n, ZIP) + (1 - f) * k_full(n, ZIP)) / k_full(n, ZIP)
        u1b = total(k_est, lambda nn, r: k_est(nn, r) * zip_scale)
        u1_aligned = total(aligned_k)
        ratio = float(ru['achieved_rps']) / float(ru['decided_rps'])
        u2_at_achieved = u2 * ratio
        # per-route terms of (a) and of (b), in points of utilisation
        a_route = {r: lam[r] * (k_est(n, r) - k_full(n, r)) / CORES for r in ROUTES}
        b_zip = lam_zip * (k_full(n, ZIP) - (f * k_trunc(n, ZIP) + (1 - f) * k_full(n, ZIP))) / CORES
        # share of U3's predicted load that is on routes whose CPU time was not used
        sub = [r for r in ROUTES if route_data[(n, r)]['cpu_withheld']]
        sub_share = sum(lam[r] * k_full(n, r) for r in sub) / CORES / u1 if u1 else 0.0
        stages.append({
            'rung': int(ru['rung']), 'rps_decided': float(ru['decided_rps']), 'rps_achieved': float(ru['achieved_rps']),
            'zip_requests_counted': pz['requests'], 'zip_requests_not_200': non200, 'f': f,
            'M': m, 'U0': u0, 'U0_committed': u0_commit, 'U1': u1, 'U2': u2, 'U3': u3,
            'delta': u0 - m, 'a': u0 - u1, 'b': u1 - u2, 'c': u2 - m, 'c1': u2 - u3, 'c2': u3 - m, 'c3': u2_at_achieved - u2,
            'b_other_order': u0 - u1b, 'a_other_order': u1b - u2,
            'a_aligned': u0 - u1_aligned,
            'a_by_route': a_route, 'b_zip': b_zip,
            'cpu_not_used_routes': sub, 'cpu_not_used_share_of_U1': sub_share,
        })
    sums = {key: sum(s[key] for s in stages) for key in ('M', 'U0', 'U1', 'U2', 'U3', 'delta', 'a', 'b', 'c', 'c1', 'c2', 'c3', 'b_other_order', 'a_other_order', 'a_aligned')}
    d = sums['delta']
    shares = {key: (sums[key] / d if d else None) for key in ('a', 'b', 'c', 'c1', 'c2', 'c3', 'a_other_order', 'b_other_order', 'a_aligned')}
    share_range = {}
    for key in ('a', 'b', 'c', 'c1', 'c2'):
        vals = [stg[key] / stg['delta'] for stg in stages if stg['delta'] > 0.005]
        share_range[key] = [min(vals), max(vals)] if vals else None
    result['scenarios'].append({
        'share_range_over_stages': share_range, 'stages_counted_in_share_range': sum(1 for stg in stages if stg['delta'] > 0.005),
        'id': sid, 'rows': n, 'mix': lad['mix'], 'usable_stages': len(stages), 'stages': stages, 'sums': sums, 'shares_of_delta_sum': shares,
        'abs_delta_sum': sum(abs(s['delta']) for s in stages),
        'a_by_route_sum': {r: sum(s['a_by_route'][r] for s in stages) for r in ROUTES},
    })

result['self_check_max_abs_difference_U0_vs_committed_point'] = max_u0_diff
result['idle_rate_in_points_of_utilisation'] = {str(n): v for n, v in idle_points.items()}
if max_u0_diff > 1e-9:
    sys.stderr.write('allocate: U0 does not reproduce the committed point utilisation (max difference %g); nothing written\n' % max_u0_diff)
    sys.exit(3)

# per row count and route: the three views of one request, so the inputs behind the tables can be read
result['route_inputs'] = [
    {'rows': n, 'route': r, **{kk: vv for kk, vv in route_data[(n, r)].items()}, 'k_est': k_est(n, r), 'k_full': k_full(n, r),
     'k_trunc': k_trunc(n, r), 'cpu_used_full': cpu_full(n, r), 'cpu_used_trunc': cpu_trunc(n, r), 'k_aligned': aligned_k(n, r)}
    for n in SCALES for r in ROUTES
]

with open(os.path.join(HERE, 'allocation.json'), 'w', encoding='utf-8') as fh:
    json.dump(result, fh, indent=1, ensure_ascii=False)
    fh.write('\n')


# ---- tables ----------------------------------------------------------------------------------------------------------------------
def pt(x):
    return '%+.2f' % (100 * x)


def pc(x):
    return 'n/a' if x is None else '%+.0f%%' % (100 * x)


lines = []
lines.append('Utilisation in percentage points. delta = U0 - M (predicted minus measured). Shares are of the sum of delta over the usable stages.\n')
lines.append('### Per scenario (sum over the usable stages)\n')
lines.append('| scenario | rows | usable stages | sum delta | (a) | (b) | (c) | (c1) | (c2) | (a) share | (b) share | (c) share | (c1) share | (c2) share |')
lines.append('|---|---|---|---|---|---|---|---|---|---|---|---|---|---|')
for s in result['scenarios']:
    u, h = s['sums'], s['shares_of_delta_sum']
    lines.append('| %s | %d | %d | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |' % (
        s['id'], s['rows'], s['usable_stages'], pt(u['delta']), pt(u['a']), pt(u['b']), pt(u['c']), pt(u['c1']), pt(u['c2']),
        pc(h['a']), pc(h['b']), pc(h['c']), pc(h['c1']), pc(h['c2'])))
lines.append('\n### Range of each share over the single stages (stages with delta above 0.5 points)\n')
lines.append('| scenario | stages | (a) | (b) | (c) | (c1) | (c2) |')
lines.append('|---|---|---|---|---|---|---|')
for s in result['scenarios']:
    rg = s['share_range_over_stages']
    cell = lambda key: 'n/a' if rg[key] is None else '%+.0f%% to %+.0f%%' % (100 * rg[key][0], 100 * rg[key][1])
    lines.append('| %s | %d | %s | %s | %s | %s | %s |' % (s['id'], s['stages_counted_in_share_range'], cell('a'), cell('b'), cell('c'), cell('c1'), cell('c2')))
lines.append('\nThe database\'s idle CPU rate read in the per-request records, in points of the 2-core utilisation: ' + ', '.join('%d rows %.2f' % (n, v * 100) for n, v in idle_points.items()) + '.')
lines.append('\n### The other order, and the (a) aligned to 10,000 rows\n')
lines.append('| scenario | (a) main | (b) main | (b\'\') other order | (a\'\') other order | (a) aligned | (a) share main | (a) share aligned | gap of the two (a) shares, points |')
lines.append('|---|---|---|---|---|---|---|---|---|')
for s in result['scenarios']:
    u, h = s['sums'], s['shares_of_delta_sum']
    gap = None if h['a'] is None else abs(h['a'] - h['a_aligned']) * 100
    lines.append('| %s | %s | %s | %s | %s | %s | %s | %s | %s |' % (
        s['id'], pt(u['a']), pt(u['b']), pt(u['b_other_order']), pt(u['a_other_order']), pt(u['a_aligned']),
        pc(h['a']), pc(h['a_aligned']), 'n/a' if gap is None else '%.1f' % gap))
lines.append('\n### Per stage\n')
for s in result['scenarios']:
    lines.append('**%s** (%d rows)\n' % (s['id'], s['rows']))
    lines.append('| stage | rps | M | U0 | U1 | U2 | U3 | delta | (a) | (b) | (c) | (c1) | (c2) | (c3) | f | ZIP not 200 / counted | (a) aligned | CPU not used: share of U1 |')
    lines.append('|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|')
    for st in s['stages']:
        lines.append('| %d | %g | %.2f | %.2f | %.2f | %.2f | %.2f | %s | %s | %s | %s | %s | %s | %s | %.2f | %d / %d | %s | %.1f%% |' % (
            st['rung'], st['rps_decided'], 100 * st['M'], 100 * st['U0'], 100 * st['U1'], 100 * st['U2'], 100 * st['U3'],
            pt(st['delta']), pt(st['a']), pt(st['b']), pt(st['c']), pt(st['c1']), pt(st['c2']), pt(st['c3']),
            st['f'], st['zip_requests_not_200'], st['zip_requests_counted'], pt(st['a_aligned']), 100 * st['cpu_not_used_share_of_U1']))
    lines.append('')
lines.append('### (a) by route (sum over the usable stages, points: estimate minus measured counts)\n')
short = {r: r.replace('GET ', '') for r in ROUTES}
lines.append('| scenario | ' + ' | '.join(short[r] for r in ROUTES) + ' |')
lines.append('|---|' + '---|' * len(ROUTES))
for s in result['scenarios']:
    lines.append('| %s | ' % s['id'] + ' | '.join(pt(s['a_by_route_sum'][r]) for r in ROUTES) + ' |')
lines.append('\n### What one request is taken as (seconds of database CPU time of one request)\n')
lines.append('| rows | route | k^est | k^full (counts) | k^trunc | CPU time used for full | CPU time used for trunc | CPU time withheld |')
lines.append('|---|---|---|---|---|---|---|---|')
for ri in result['route_inputs']:
    lines.append('| %d | %s | %.5g | %.5g | %.5g | %.5g | %.5g | %s |' % (
        ri['rows'], short[ri['route']], ri['k_est'], ri['k_full'], ri['k_trunc'], ri['cpu_used_full'], ri['cpu_used_trunc'], 'yes (k^full used)' if ri['cpu_withheld'] else 'no'))
lines.append('')
with open(os.path.join(HERE, 'allocation-tables.md'), 'w', encoding='utf-8') as fh:
    fh.write('\n'.join(lines))
sys.stdout.write('self-check: max abs difference of U0 from the committed point utilisation = %g\n' % max_u0_diff)
