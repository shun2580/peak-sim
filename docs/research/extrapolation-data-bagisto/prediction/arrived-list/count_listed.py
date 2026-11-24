#!/usr/bin/env python3
"""Count test A on the listed routes only, with the same tool and the same rules as the count of all routes.

  python3 -I count_listed.py --out <dir> --list <list-arrived.txt> --judge-a <judge_a.py> \
      --app <name> <estimate.json> <marked.json> <measure dir or attempts.jsonl> <variance dir>

What it does, and nothing else:

  1. keeps from the committed estimate file and the committed marked file only the routes of the list (`METHOD /uri`, one per line) and
     writes the two filtered copies to `<out>/filtered/`. Every other field of a route and of the file is copied as it is, except
     `marked_count` of the marked file (the number of routes it now holds) and a `filtered_to_list` field that names the list's SHA-256;
  2. runs `judge_a.py` on the filtered copies and the records as they are (measure and variance are not touched: a measured route that
     is not in the filtered estimate is listed by the comparison tool as measured without an estimate and is not counted). `judge_a.py`
     and the comparison tool it pins (`compare_test2.py`, SHA-256 checked by `judge_a.py`) are not changed; no rule is copied here;
  3. reads `a-verdict.json` and writes `count-listed.md` and `count-listed.json`: the verdict (rule B decides, rule A shown), the four
     numbers of condition 1a, the denominator and the routes not measured per quantity, the routes of the list that have no complete
     estimate, and whether the line is met on the listed routes.

The line is the one of the full count: at least 80% of all routes inside the lowest standard and at least 80% of the unmarked routes
inside the goal, for queries and for rows examined. A quantity whose denominator is under 5 routes is undecidable, and an undecidable
count does not meet the line. The comparison tool has a second ground for undecidable that is the same on any number of routes: more
than 20% of the routes that have an estimate could not be measured (D95). It applies here as it does there; the two grounds are shown
apart so that nobody reads the second as the first.

"Marked" in condition 1a is the mark of the plan (poc/runtime/bin/marked-routes), not the capture's mark "data may not have arrived"
that the list was made with.

Exit codes: 0 counted (whatever the verdict); 2 wrong usage or a list route repeated; 3 judge_a.py stopped (its own exit code is shown).
"""
import argparse, hashlib, json, os, subprocess, sys


def sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        h.update(fh.read())
    return h.hexdigest()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', required=True)
    ap.add_argument('--list', required=True, help='one `METHOD /uri` per line')
    ap.add_argument('--judge-a', required=True, help='path of judge_a.py (unchanged)')
    ap.add_argument('--app', nargs=5, metavar=('NAME', 'ESTIMATE', 'MARKED', 'MEASURE', 'VARIANCE'), required=True)
    a = ap.parse_args()
    name, est_path, marked_path, measure, variance = a.app

    keys = [l.rstrip('\n') for l in open(a.list, encoding='utf-8') if l.strip()]
    if len(set(keys)) != len(keys):
        print('count_listed: a route is repeated in the list', file=sys.stderr)
        return 2
    wanted = set(keys)
    list_sha = sha256(a.list)

    est = json.load(open(est_path, encoding='utf-8'))
    marked = json.load(open(marked_path, encoding='utf-8'))
    by_route = {r['route']: r for r in est['routes']}
    missing = sorted(k for k in keys if k not in by_route)
    incomplete = sorted((k, by_route[k].get('status'), sorted({str(u.get('reason')) for u in by_route[k].get('unavailable', [])})) for k in keys
                        if k in by_route and not by_route[k].get('complete'))

    fest = dict(est)
    fest['routes'] = [r for r in est['routes'] if r['route'] in wanted]
    fest['filtered_to_list'] = list_sha
    fmarked = dict(marked)
    fmarked['routes'] = [r for r in marked.get('routes', []) if r['route'] in wanted]
    fmarked['marked_count'] = len(fmarked['routes'])
    fmarked['filtered_to_list'] = list_sha

    fdir = os.path.join(a.out, 'filtered')
    os.makedirs(fdir, exist_ok=True)
    fest_path = os.path.join(fdir, os.path.basename(est_path))
    fmarked_path = os.path.join(fdir, os.path.basename(marked_path))
    for p, d in ((fest_path, fest), (fmarked_path, fmarked)):
        with open(p, 'w', encoding='utf-8') as f:
            json.dump(d, f, indent=1, sort_keys=True)
            f.write('\n')

    jdir = os.path.join(a.out, 'judge')
    run = subprocess.run(['python3', '-I', a.judge_a, '--out', jdir, '--app', name, fest_path, fmarked_path, measure, variance],
                         capture_output=True, text=True)
    open(os.path.join(a.out, 'judge-a.stdout'), 'w').write(run.stdout + run.stderr)
    if run.returncode != 0:
        print('count_listed: judge_a.py stopped with exit %d:\n%s%s' % (run.returncode, run.stdout, run.stderr), file=sys.stderr)
        return 3

    v = json.load(open(os.path.join(jdir, 'a-verdict.json')))['apps'][name]
    sh = v['shares']['all']
    per_q = {}
    for q in ('queries', 'rows_examined'):
        t = sh[q]
        per_q[q] = {'routes_in_list': len(keys), 'with_estimate': t['with_estimate'], 'denominator': t['denominator'], 'unmeasured': t['unmeasured'],
                    'no_estimate': t['no_estimate'],
                    'denominator_under_5': t['denominator'] < 5,
                    'unmeasured_over_20_percent': t['unmeasured'] * 100 > 20 * t['with_estimate']}
    undecidable = v['verdict'] == 'undecidable'
    line_met = bool(v['condition_1a_short_all_zero'])
    res = {'list_sha256': list_sha, 'routes_in_list': len(keys), 'list_routes_missing_from_estimate': missing,
           'list_routes_with_incomplete_estimate': [{'route': k, 'status': s, 'reasons': r} for k, s, r in incomplete],
           'verdict_rule_B': v['verdict'], 'reasons': v['reasons'], 'verdict_rule_A_shown': v['rule_A_verdict'], 'rule_A_reasons': v['rule_A_reasons'],
           'per_quantity': per_q, 'condition_1a': v['condition_1a'], 'line_met_on_listed_routes': line_met,
           'condition_3_misses': len(v['condition_3_misses']), 'not_measured_with_estimate': v['not_measured_with_estimate']}
    json.dump(res, open(os.path.join(a.out, 'count-listed.json'), 'w'), indent=1, sort_keys=True)
    open(os.path.join(a.out, 'count-listed.json'), 'a').write('\n')
    with open(os.path.join(a.out, 'count-listed.sha256'), 'w') as f:
        for label, p in (('list', a.list), ('estimate', est_path), ('marked', marked_path), ('filtered-estimate', fest_path),
                         ('filtered-marked', fmarked_path), ('a-verdict.json', os.path.join(jdir, 'a-verdict.json'))):
            f.write('%s  %s\n' % (sha256(p), label))

    L = ['# Count on the listed routes (count_listed.py)\n',
         'Routes in the list: %d (list SHA-256 `%s`). Application: `%s`. The tool is `judge_a.py`, unchanged; the comparison tool it pins is unchanged.\n' % (len(keys), list_sha, name),
         '**Verdict (rule B): %s.** %s\n' % (v['verdict'].upper(), '; '.join(v['reasons'])),
         'Rule A, shown and not deciding: %s. %s\n' % (v['rule_A_verdict'], '; '.join(v['rule_A_reasons'])),
         '| Quantity | Routes in list | With a complete estimate | Measured (denominator) | Not measured | Denominator under 5 | Not measured over 20% of those with an estimate |',
         '| --- | --- | --- | --- | --- | --- | --- |']
    for q, t in per_q.items():
        L.append('| %s | %d | %d | %d | %d | %s | %s |' % (q, t['routes_in_list'], t['with_estimate'], t['denominator'], t['unmeasured'],
                                                          'yes' if t['denominator_under_5'] else 'no', 'yes' if t['unmeasured_over_20_percent'] else 'no'))
    L.append('')
    L.append('| Condition 1a on the listed routes | Inside | Denominator | Needed | Short |\n| --- | --- | --- | --- | --- |')
    for k, c in v['condition_1a'].items():
        L.append('| %s | %d | %d | %s | %s |' % (k, c['inside'], c['denominator'], 'n/a' if undecidable else c['needed'], 'n/a (undecidable)' if undecidable else c['short']))
    L.append('')
    L.append('**The line is %s on the listed routes.** %s\n' % (
        'met' if line_met else 'not met',
        'All four short numbers are 0.' if line_met else ('The count is undecidable, which does not meet the line.' if undecidable else 'At least one short number is not 0.')))
    if missing:
        L.append('Routes of the list that are not in the estimate (not counted): ' + ', '.join('`%s`' % k for k in missing) + '.\n')
    if incomplete:
        L.append('Routes of the list whose estimate is not complete (not counted, "no estimate"): ' +
                 ', '.join('`%s` (%s)' % (k, ', '.join(map(str, r)) or str(s)) for k, s, r in incomplete) + '.\n')
    if not missing and not incomplete:
        L.append('Every route of the list has a complete estimate.\n')
    L.append('Routes outside the goal on the listed routes (condition 3 shown for these routes only): %d. Not measured, with an estimate: %d.\n' %
             (len(v['condition_3_misses']), len(v['not_measured_with_estimate'])))
    open(os.path.join(a.out, 'count-listed.md'), 'w').write('\n'.join(L) + '\n')
    print('count_listed: %s; line %s on %d listed routes -> %s' % (v['verdict'], 'met' if line_met else 'not met', len(keys), a.out))
    return 0


if __name__ == '__main__':
    sys.exit(main())
