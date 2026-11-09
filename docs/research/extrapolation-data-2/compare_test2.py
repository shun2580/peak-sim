#!/usr/bin/env python3
"""Second extrapolation test (200,000 rows): compare the committed estimates with the cold measurements.

Rebuild:
  python3 -I compare_test2.py --out <dir> \
      --app fixtures  <estimate.json> <marked.json> <measure dir or attempts.jsonl> <variance dir> \
      --app bookstack <estimate.json> <marked.json> <measure dir or attempts.jsonl> <variance dir>
Inputs are read only; no database, no app; the same inputs always give the same output (compare.json, compare.md).

  estimate.json  output of bin/estimate --candidate=H1_RANGE at N (per route: queries, rows_examined, rows_examined_upper,
                 peak_memory, complete); the marked list is checked against `marked` of each route
  marked.json    the list of marked routes made from explain.json (bin/marked-routes), committed with the estimates
  measure        the --out directory of bin/measure --scales=<N> (attempts.jsonl), cold request = attempt 2
  variance       the --out directory of bin/variance-run on the N-row database: every <...>/attempt.jsonl under it,
                 cold request = attempt 2 (the three extra cold values of the marked routes)
  Both can be a comma-separated list of directories (a re-run after the first). For a route that appears in a later
  directory of the list, the tries of the later directory replace the tries of the earlier ones for that route; the
  replaced tries are not used for any value, but their failures are listed as `superseded`.

Failed cold tries (D126 and its addition D126b): a try that has no ok cold value (attempt 2) but has a record at the size
is a failed try. Each is counted by class: `timeout` (class timeout, or any record whose detail says the request ran
longer than 600 seconds: the 600-second cut, whatever its class name), `422` (status 422),
`500` (status 500, not a timeout: the limit of the app), `exception` (class exception with another status), `other`
(any other class, named). Rule B drops a failed try from the largest-of-all-cold-values, except that a marked route with
at least one `timeout` try is NOT measured under rule B ("600 seconds or more", D126b). A route with no cold value left is not measured.

Rules (docs/simulator-spec.md 7.1 and 7.4; nothing here changes them):
  rule B (decides)  unmarked route: the single cold value of `measure`; marked route: the largest of ALL cold values at N
                    (the one of `measure` and those of `variance`)
  rule A (shown)    every route: the single cold value of `measure`
  quantities        queries, rows examined (decide the test), peak memory (reported only)
  tolerances        lowest standard 5% / 20% / 30%; goal 2% / 10% / 30%  (relative error |est - meas| / meas, edge inside;
                    a measured 0 matches when the estimate is below 0.5)
  pass              both queries and rows examined within the lowest standard for 80% or more of their own denominator;
                    indeterminate (never a pass) when a denominator has fewer than 5 routes or more than 20% of the routes
                    with an estimate could not be measured (D95)
  denominator       per quantity: the route has an estimate for it and a cold value at N was measured (class ok, value present)
Exit 0 whatever the verdict."""
import argparse, glob, json, os, sys

QUANTS = ('queries', 'rows_examined', 'peak_memory')
FIELD = {'queries': 'queries', 'rows_examined': 'rows_examined', 'peak_memory': 'peak_mem'}
TOL_MIN = {'queries': 5, 'rows_examined': 20, 'peak_memory': 30}
TOL_GOAL = {'queries': 2, 'rows_examined': 10, 'peak_memory': 30}
GATING = ('queries', 'rows_examined')
PASS_SHARE, MIN_DEN, MAX_UNMEASURED = 80, 5, 20
GROUPS = ('all', 'unmarked', 'marked')


def compare(pred, meas, tol):
    """The same arithmetic as ExtrapolationJudge::compare (poc/runtime/src/Judge)."""
    if meas == 0:
        return (None, pred < 0.5)
    return (abs(pred - meas) / abs(meas), abs(pred - meas) * 100 <= tol * abs(meas) * (1 + 1e-12))


def read_jsonl(path):
    """Read the JSON records of one file. The record of a route that answers with a file (for example the zip export)
    can have the body in front of the JSON text on the same line, so each line is read from its first `{"route"`;
    a line without one is not a record and is skipped. Reading only: the record is never rewritten."""
    out = []
    with open(path, 'rb') as f:
        for raw in f:
            line = raw.decode('utf-8', 'replace')
            i = line.find('{"route"')
            if i >= 0:
                out.append(json.loads(line[i:]))
    return out


def key_of(a):
    return f"{a.get('method', '')} {a.get('route', '')}"


def cold_ok(a, at):
    return int(a.get('attempt', 0)) == 2 and int(a.get('scale', -1)) == at and a.get('axis', 'N') == 'N' and a.get('class') == 'ok'


def failure_class(rec):
    """The class of a failed try, D126/D126b: timeout | 422 | 500 | exception | other:<class>."""
    c, st = rec.get('class'), rec.get('status')
    # by what the record says, not by its class name: a 600-second cut can be recorded as class exception (status 500)
    if c == 'timeout' or 'ran longer than 600 seconds' in str(rec.get('detail', '')):
        return 'timeout'
    if st == 422 or c == 'validation_422':
        return '422'
    if st == 500:
        return '500'
    if c == 'exception':
        return 'exception'
    return 'other:' + str(c)


FAIL_CLASSES = ('timeout', '422', '500', 'exception', 'other')


def make_try(records, at, source):
    """One try of one route from its records at the size: ok (the cold record) or failed (the worst record), or None."""
    here = [a for a in records if int(a.get('scale', -1)) == at and a.get('axis', 'N') == 'N']
    cold = [a for a in here if cold_ok(a, at)]
    if cold:
        return {'source': source, 'ok': True, 'cold': cold[0]}
    bad = [a for a in here if a.get('class') != 'ok']
    if not bad:
        return None
    rec = max(bad, key=lambda a: int(a.get('attempt', 0)))
    return {'source': source, 'ok': False, 'class': failure_class(rec), 'class_raw': rec.get('class'), 'status': rec.get('status'),
            'attempt': rec.get('attempt'), 'detail': str(rec.get('detail', ''))[:120]}


def split_dirs(x):
    dirs = [p for p in (x or '').split(',') if p]
    for p in dirs:
        if not os.path.exists(p):
            sys.exit(f'input not found: {p}')
    return dirs


def load_cold(measure, variance, at):
    """Returns (measure tries per route, variance tries per route, superseded tries, file count); the last directory wins per route."""
    mtry, vtry, sup, nfiles = {}, {}, [], 0
    for i, d in enumerate(split_dirs(measure), 1):
        path = os.path.join(d, 'attempts.jsonl') if os.path.isdir(d) else d
        by = {}
        for a in read_jsonl(path):
            by.setdefault(key_of(a), []).append(a)
        for k, recs in by.items():
            t = make_try(recs, at, path)
            if t:
                t['label'] = f'measurement run {i}'
                if k in mtry:
                    sup.append(dict(mtry[k], route=k, kind='measure'))
                mtry[k] = t
    for i, d in enumerate(split_dirs(variance), 1):
        files = sorted(glob.glob(os.path.join(d, '**', 'attempt.jsonl'), recursive=True))
        nfiles += len(files)
        here = {}
        for p in files:
            by = {}
            for a in read_jsonl(p):
                by.setdefault(key_of(a), []).append(a)
            for k, recs in by.items():
                t = make_try(recs, at, p)
                if t:
                    t['label'] = f'variance run {i}'
                    here.setdefault(k, []).append(t)
        for k, ts in here.items():
            for t in vtry.get(k, []):
                sup.append(dict(t, route=k, kind='variance'))
            vtry[k] = ts
    return mtry, vtry, sup, nfiles


def fail_text(f):
    return f"{f['class']}{'' if f['class'] != 'timeout' else ' (600 seconds or more)'}"


def value(a, q):
    v = a.get(FIELD[q])
    return None if v is None else float(v)


def estimate_of(r, q):
    """(value, reason) of the estimate of one quantity of one route."""
    if q == 'peak_memory':
        p = r.get('peak_memory') or {}
        return (float(p['value']), None) if p.get('status') == 'predicted' and 'value' in p else (None, 'peak_memory ' + str(p.get('status', 'missing')) + (': ' + str(p['reason']) if 'reason' in p else ''))
    if not r.get('complete'):
        return None, 'estimate incomplete (' + str(r.get('status', 'unknown')) + ')'
    v = r.get(q)
    return (None, 'no ' + q) if v is None else (float(v), None)


def build_app(name, est_path, marked_path, measure, variance):
    est = json.load(open(est_path))
    marked_doc = json.load(open(marked_path))
    at = int(est['at'])
    marked = {r['route'] for r in marked_doc.get('routes', [])}
    mtry, vtry, superseded, nfiles = load_cold(measure, variance, at)
    rows, problems = [], []
    seen = set()
    for r in est['routes']:
        route = r['route']
        seen.add(route)
        if (r.get('marked') is True) != (route in marked):
            problems.append(f'{route}: `marked` of the estimate ({r.get("marked")}) differs from the marked list')
        is_marked = route in marked
        m = mtry.get(route)
        c1 = m['cold'] if m and m['ok'] else None
        vt = vtry.get(route, [])
        colds = ([c1] if c1 else []) + [t['cold'] for t in vt if t['ok']]
        fails = ([dict(m, route=route, kind='measure')] if m and not m['ok'] else []) + [dict(t, route=route, kind='variance') for t in vt if not t['ok']]
        timed_out = any(f['class'] == 'timeout' for f in fails)
        sup_here = [f for f in superseded if f['route'] == route]
        def tdesc(t, kind, state):
            return {'kind': kind, 'label': t.get('label'), 'state': state, 'ok': t['ok'],
                    'what': ('ok' if t['ok'] else fail_text(t)), 'rows_examined': (value(t['cold'], 'rows_examined') if t['ok'] else None)}
        tries = ([tdesc(m, 'measure', 'used')] if m else []) + [tdesc(t, 'variance', 'used') for t in vt] + \
                [tdesc(t, t['kind'], 'superseded (not used)') for t in sup_here]
        row = {'route': route, 'marked': is_marked, 'tries': tries, 'cold_runs': {'measure': 1 if c1 else 0, 'variance': sum(1 for t in vt if t['ok'])},
               'failed_tries': fails, 'timed_out': timed_out, 'q': {}}
        failwhy = ('; failed tries: ' + ', '.join(fail_text(f) for f in fails)) if fails else ''
        for q in QUANTS:
            pred, why = estimate_of(r, q)
            vals = [v for v in (value(a, q) for a in colds) if v is not None]
            v1 = value(c1, q) if c1 else None
            cell = {'predicted': pred, 'cold_values': vals, 'cold_first': v1}
            if pred is None:
                cell.update(category='no_estimate', reason=why)
            elif not vals:
                cell.update(category='unmeasured', reason=('no cold value at the size' if not colds else q + ' not in the cold record') + failwhy)
            else:
                cell.update(category='in_denominator')
                # rule A: the single cold value; rule B: unmarked = the same, marked = the largest of all cold values
                ref = {'A': v1, 'B': max(vals) if is_marked else v1}
                for rule in ('A', 'B'):
                    if rule == 'B' and is_marked and timed_out:      # D126b: a timeout makes a marked route not measured
                        cell[rule] = {'category': 'unmeasured', 'reason': '600 seconds or more (at least one cold try timed out)' + failwhy}
                        continue
                    if ref[rule] is None:
                        cell[rule] = {'category': 'unmeasured', 'reason': 'the cold value of the measurement run is missing' + failwhy}
                        continue
                    e, m_ok = compare(pred, ref[rule], TOL_MIN[q])
                    _, g_ok = compare(pred, ref[rule], TOL_GOAL[q])
                    cell[rule] = {'category': 'in_denominator', 'measured': ref[rule], 'relative_error': e, 'within_min': m_ok, 'within_goal': g_ok}
            row['q'][q] = cell
        rows.append(row)
    not_estimated = sorted(k for k in mtry if k not in seen)
    outside_failed = [dict(mtry[k], route=k) for k in not_estimated if not mtry[k]['ok']]
    rows.sort(key=lambda x: x['route'])
    used = [f for r in rows for f in r['failed_tries']]
    counts = {g: {c: 0 for c in FAIL_CLASSES} for g in ('used', 'superseded')}
    for g, lst in (('used', used), ('superseded', superseded)):
        for f in lst:
            counts[g][f['class'].split(':')[0]] += 1
    return {'app': name, 'at': at, 'rows': rows, 'problems': problems, 'measured_without_estimate': not_estimated,
            'outside_failed': outside_failed, 'variance_files': nfiles, 'marked_count': len(marked), 'failed_tries': used, 'superseded_tries': superseded,
            'failure_counts': counts}


def category(cell, rule):
    if cell['category'] != 'in_denominator':
        return cell['category']
    return cell[rule]['category']


def tally(rows, rule, group, q):
    sel = [r for r in rows if group == 'all' or (group == 'marked') == r['marked']]
    t = {'denominator': 0, 'within_min': 0, 'within_goal': 0, 'unmeasured': 0, 'no_estimate': 0, 'routes': len(sel)}
    for r in sel:
        cell = r['q'][q]
        c = category(cell, rule)
        if c == 'in_denominator':
            t['denominator'] += 1
            t['within_min'] += bool(cell[rule]['within_min'])
            t['within_goal'] += bool(cell[rule]['within_goal'])
        else:
            t[c] += 1
    t['with_estimate'] = t['denominator'] + t['unmeasured']
    return t


def share(n, d):
    return None if d == 0 else round(n * 100 / d, 2)


def verdict(rows, rule):
    reasons = []
    for q in GATING:
        t = tally(rows, rule, 'all', q)
        if t['denominator'] < MIN_DEN:
            reasons.append(f"{q}: the denominator has {t['denominator']} routes, fewer than {MIN_DEN}")
        if t['unmeasured'] * 100 > MAX_UNMEASURED * t['with_estimate']:
            reasons.append(f"{q}: {t['unmeasured']} of {t['with_estimate']} routes with an estimate could not be measured, more than {MAX_UNMEASURED}%")
    if reasons:
        return 'indeterminate', reasons
    out, v = [], 'pass'
    for q in GATING:
        t = tally(rows, rule, 'all', q)
        ok = t['within_min'] * 100 >= PASS_SHARE * t['denominator']
        out.append(f"{q}: {t['within_min']} of {t['denominator']} inside +-{TOL_MIN[q]}% ({'enough' if ok else 'not enough'}), needs {PASS_SHARE}%")
        if not ok:
            v = 'fail'
    return v, out


def summarise(rows):
    s = {}
    for rule in ('A', 'B'):
        v, why = verdict(rows, rule)
        s[rule] = {'verdict': v, 'reasons': why, 'shares': {g: {q: tally(rows, rule, g, q) for q in QUANTS} for g in GROUPS}}
    return s


def top_errors(app_rows, rule, n=10):
    ent = []
    for r in app_rows:
        for q in GATING:
            cell = r['q'][q]
            if category(cell, rule) != 'in_denominator':
                continue
            x = cell[rule]
            key = x['relative_error'] if x['relative_error'] is not None else (0.0 if x['within_min'] else float('inf'))
            ent.append((key, r['route'], q, r['marked'], cell['predicted'], x['measured'], x['relative_error'], x['within_min'], x['within_goal']))
    ent.sort(key=lambda e: (-e[0], e[1], e[2]))
    return [dict(zip(('route', 'quantity', 'marked', 'predicted', 'measured', 'relative_error', 'within_min', 'within_goal'), e[1:])) for e in ent[:n]]


def width_table(app, est_by_route):
    out = []
    for r in app['rows']:
        if not r['marked']:
            continue
        cell = r['q']['rows_examined']
        vals = cell['cold_values']
        upper = est_by_route[r['route']].get('rows_examined_upper')
        row = {'route': r['route'], 'point': cell['predicted'], 'upper': upper, 'cold_values': vals,
               'cold_min': min(vals) if vals else None, 'cold_max': max(vals) if vals else None, 'cold_count': len(vals),
               'cold_runs': r['cold_runs']}
        if vals and cell['predicted'] is not None:
            row['point_vs_range'] = 'below' if cell['predicted'] < min(vals) else 'above' if cell['predicted'] > max(vals) else 'inside'
            if upper is not None:
                row['upper_vs_range'] = 'below' if upper < min(vals) else 'above' if upper > max(vals) else 'inside'
                row['point_and_upper_enclose_range'] = cell['predicted'] <= min(vals) and upper >= max(vals)
        out.append(row)
    return out


def pct(x):
    return 'n/a' if x is None else f'{x:.2f}%'


def fnum(x):
    return 'n/a' if x is None else f'{x:,.1f}' if abs(x - round(x)) > 1e-9 else f'{x:,.0f}'


def markdown(res):
    L = []
    p = L.append
    p(f"# Comparison at N = {res['at']:,}\n")
    p('Rule B decides; rule A is shown beside it. Lowest standard: queries +-5%, rows examined +-20%. Goal: queries +-2%, rows examined +-10%. Peak memory +-30% (reported only).\n')
    for scope, d in res['scopes'].items():
        p(f"## {scope}\n")
        for rule, label in (('B', 'Rule B (decides)'), ('A', 'Rule A (shown, does not decide)')):
            s = d['summary'][rule]
            p(f"**{label}: {s['verdict']}**\n")
            for w in s['reasons']:
                p('- ' + w)
            p('\n| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |\n| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |')
            for g in GROUPS:
                for q in QUANTS:
                    t = s['shares'][g][q]
                    p(f"| {g} | {q} | {t['denominator']} | {t['within_min']} | {pct(share(t['within_min'], t['denominator']))} | {t['within_goal']} | {pct(share(t['within_goal'], t['denominator']))} | {t['unmeasured']} | {t['no_estimate']} |")
            p('')
    for app in res['apps']:
        p(f"## {app['app']}: largest errors (rule B, queries and rows examined)\n")
        p('| # | Route | Quantity | Marked | Predicted | Measured | Relative error | Lowest | Goal |\n| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |')
        for i, e in enumerate(app['top_errors_B'], 1):
            p(f"| {i} | `{e['route']}` | {e['quantity']} | {'yes' if e['marked'] else 'no'} | {fnum(e['predicted'])} | {fnum(e['measured'])} | {'n/a' if e['relative_error'] is None else pct(e['relative_error'] * 100)} | {'yes' if e['within_min'] else 'no'} | {'yes' if e['within_goal'] else 'no'} |")
        p(f"\n## {app['app']}: marked routes, the point and the upper side against the cold values (rows examined)\n")
        if not app['width_table']:
            p('No marked route.\n')
        else:
            p('| Route | Point (H1) | Upper side (H2) | Cold min | Cold max | Cold values (count of 4) | Point vs cold range | Upper vs cold range | Range enclosed by point and upper |\n| --- | ---: | ---: | ---: | ---: | --- | --- | --- | --- |')
            for w in app['width_table']:
                p(f"| `{w['route']}` | {fnum(w['point'])} | {fnum(w['upper'])} | {fnum(w['cold_min'])} | {fnum(w['cold_max'])} | {', '.join(fnum(v) for v in w['cold_values'])} ({w['cold_count']}/4) | {w.get('point_vs_range', 'n/a')} | {w.get('upper_vs_range', 'n/a')} | { {True: 'yes', False: 'no'}.get(w.get('point_and_upper_enclose_range'), 'n/a')} |")
            p('')
        if app['width_table']:
            p(f"\n### {app['app']}: every try of the marked routes (which were used)\n")
            p('| Route | Kind | Run | State | Result | Rows examined |\n| --- | --- | --- | --- | --- | ---: |')
            for r in app['rows']:
                if r['marked']:
                    for t in r['tries']:
                        p(f"| `{r['route']}` | {t['kind']} | {t['label']} | {t['state']} | {t['what']} | {fnum(t['rows_examined'])} |")
            p('')
        part = [w for w in app['width_table'] if 0 < w['cold_count'] < 4]
        if part:
            p('**Marked routes with fewer than four cold values (rule B takes the largest of those that exist; the rules do not say what to do when one is missing):** ' + ', '.join(f"`{w['route']}` ({w['cold_count']}/4)" for w in part) + '\n')
        p(f"## {app['app']}: failed cold tries by class\n")
        fc = app['failure_counts']
        p('| Which tries | ' + ' | '.join(FAIL_CLASSES) + ' |\n| --- | ' + ' | '.join('---:' for _ in FAIL_CLASSES) + ' |')
        p('| used (rule B drops them; a timeout on a marked route makes it not measured) | ' + ' | '.join(str(fc['used'][c]) for c in FAIL_CLASSES) + ' |')
        p('| superseded by a re-run (not used) | ' + ' | '.join(str(fc['superseded'][c]) for c in FAIL_CLASSES) + ' |\n')
        lst = [dict(f, state='used') for f in app['failed_tries']] + [dict(f, state='superseded') for f in app['superseded_tries']]
        if lst:
            p('| Route | Marked | Kind | State | Class | Status | Detail |\n| --- | --- | --- | --- | --- | ---: | --- |')
            mk = {r['route']: r['marked'] for r in app['rows']}
            for f in sorted(lst, key=lambda f: (f['route'], f['kind'], f['state'])):
                p(f"| `{f['route']}` | {'yes' if mk.get(f['route']) else 'no'} | {f['kind']} | {f['state']} | {fail_text(f)} | {f.get('status')} | {str(f['detail']).replace('|', '/')} |")
            p('')
        tmo = [r['route'] for r in app['rows'] if r['marked'] and r['timed_out']]
        p('Marked routes not measured under rule B because of a timeout (600 seconds or more): ' + (', '.join(f'`{x}`' for x in tmo) if tmo else 'none') + '\n')
        recl = [f for f in app['failed_tries'] + app['superseded_tries'] if f['class'] == 'timeout' and f.get('class_raw') != 'timeout']
        p('Timeouts recorded under another class name (counted as timeout by their detail): ' + (', '.join(f"`{f['route']}` (class {f['class_raw']}, status {f.get('status')})" for f in recl) if recl else 'none') + '\n')
        p(f"## {app['app']}: outside a denominator\n")
        left = [(r['route'], q, r['q'][q]['category'], r['q'][q].get('reason', '')) for r in app['rows'] for q in QUANTS if r['q'][q]['category'] != 'in_denominator']
        left += [(r['route'], q, f'unmeasured (rule {rule})', r['q'][q][rule].get('reason', '')) for r in app['rows'] for q in QUANTS if r['q'][q]['category'] == 'in_denominator' for rule in ('A', 'B') if r['q'][q][rule]['category'] != 'in_denominator']
        p('none\n' if not left else '| Route | Quantity | Category | Reason |\n| --- | --- | --- | --- |\n' + '\n'.join(f'| `{a}` | {b} | {c} | {d} |' for a, b, c, d in left) + '\n')
        if app['outside_failed']:
            p('Failed tries of routes without an estimate row (outside every denominator, they do not affect the verdict):\n')
            p('| Route | Class | Status | Detail |\n| --- | --- | ---: | --- |')
            for f in app['outside_failed']:
                p(f"| `{f['route']}` | {fail_text(f)} (recorded as {f.get('class_raw')}) | {f.get('status')} | {str(f['detail']).replace('|', '/')} |")
            p('')
        if app['measured_without_estimate']:
            p('Measured but without an estimate row: ' + ', '.join(f'`{x}`' for x in app['measured_without_estimate']) + '\n')
        if app['problems']:
            p('**Inconsistencies found in the inputs:**\n')
            for x in app['problems']:
                p('- ' + x)
            p('')
    return '\n'.join(L) + '\n'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', required=True)
    ap.add_argument('--app', nargs=5, action='append', metavar=('NAME', 'ESTIMATE', 'MARKED', 'MEASURE', 'VARIANCE'), required=True)
    args = ap.parse_args()
    apps, by_route = [], {}
    for name, est, marked, measure, variance in args.app:
        a = build_app(name, est, marked, measure, variance)
        e = {r['route']: r for r in json.load(open(est))['routes']}
        a['summary'] = summarise(a['rows'])
        a['top_errors_B'] = top_errors(a['rows'], 'B')
        a['top_errors_A'] = top_errors(a['rows'], 'A')
        a['width_table'] = width_table(a, e)
        apps.append(a)
    ats = {a['at'] for a in apps}
    if len(ats) != 1:
        sys.exit(f'the apps were estimated at different sizes: {sorted(ats)}')
    scopes = {a['app']: {'summary': a['summary']} for a in apps}
    both = [dict(r, route=f"{a['app']}:{r['route']}") for a in apps for r in a['rows']]
    scopes['both apps'] = {'summary': summarise(both)}
    res = {'schema_version': '1', 'at': ats.pop(), 'rule': 'B decides, A shown (docs/simulator-spec.md 7.1)', 'scopes': scopes, 'apps': apps}
    os.makedirs(args.out, exist_ok=True)
    def strip(o):      # the path of a record file is not part of the result (the labels name the run)
        if isinstance(o, dict):
            return {k: strip(v) for k, v in o.items() if k != 'source'}
        return [strip(x) for x in o] if isinstance(o, list) else o
    res = strip(res)
    with open(os.path.join(args.out, 'compare.json'), 'w') as f:
        json.dump(res, f, indent=1, sort_keys=False, default=lambda o: None)
    with open(os.path.join(args.out, 'compare.md'), 'w') as f:
        f.write(markdown(res))
    for scope, d in scopes.items():
        print(scope, 'B:', d['summary']['B']['verdict'], '| A:', d['summary']['A']['verdict'])


if __name__ == '__main__':
    main()
