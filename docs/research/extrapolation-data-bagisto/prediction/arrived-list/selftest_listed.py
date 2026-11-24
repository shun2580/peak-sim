#!/usr/bin/env python3
"""Checks of count_listed.py against known answers, from the repository root:

  python3 -I <dir>/selftest_listed.py --work <empty scratch dir>

  1. the whole list of the fourth run's BookStack estimate (every route) must give, byte for byte in the application's entry, the
     committed verdict `docs/research/extrapolation-data-4/compare/a-verdict.json`;
  2. a part of that list (every other route) must give the same counts as a second reading written here, from the unfiltered
     comparison output (`R1/compare.json`), without the filtering of the estimate;
  3. a list of 4 routes (one not in the estimate) must give "undecidable" and "line not met", and must name the route missing from the estimate.

Exit 0 when all three hold, 1 otherwise. Reads files only; no database, no application.
"""
import argparse, json, os, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = subprocess.run(['git', 'rev-parse', '--show-toplevel'], capture_output=True, text=True).stdout.strip()
EST = 'poc/lab/predictions/extrapolation-4-bookstack-estimate.json'
MARKED = 'poc/lab/predictions/extrapolation-4-bookstack-marked.json'
MEAS = 'docs/research/extrapolation-data-4/measured-240000'
JUDGE = 'docs/research/lab-data/dryrun-286/judge/judge_a.py'
COMMITTED = 'docs/research/extrapolation-data-4/compare/a-verdict.json'


def count(out, listfile):
    r = subprocess.run(['python3', '-I', os.path.join(HERE, 'count_listed.py'), '--out', out, '--list', listfile, '--judge-a', os.path.join(ROOT, JUDGE),
                        '--app', 'bookstack', os.path.join(ROOT, EST), os.path.join(ROOT, MARKED),
                        os.path.join(ROOT, MEAS, 'bookstack/attempts.jsonl'), os.path.join(ROOT, MEAS, 'variance/bookstack')],
                       capture_output=True, text=True)
    if r.returncode != 0:
        print(r.stdout + r.stderr)
    return r.returncode == 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--work', required=True)
    a = ap.parse_args()
    os.makedirs(a.work, exist_ok=True)
    routes = [r['route'] for r in json.load(open(os.path.join(ROOT, EST)))['routes']]
    ok = True

    all_list = os.path.join(a.work, 'all.txt')
    open(all_list, 'w').write(''.join(x + '\n' for x in routes))
    c1 = count(os.path.join(a.work, 'all'), all_list)
    same = c1 and json.load(open(os.path.join(a.work, 'all/judge/a-verdict.json')))['apps']['bookstack'] == \
        json.load(open(os.path.join(ROOT, COMMITTED)))['apps']['bookstack']
    print('1. whole list = committed verdict of the application:', 'ok' if same else 'FAILED')
    ok &= bool(same)

    part = sorted(routes)[::2]
    part_list = os.path.join(a.work, 'part.txt')
    open(part_list, 'w').write(''.join(x + '\n' for x in part))
    c2 = count(os.path.join(a.work, 'part'), part_list)
    ok2 = False
    if c2:
        full = json.load(open(os.path.join(a.work, 'all/judge/R1/compare.json')))
        app = next(x for x in full['apps'] if x['app'] == 'bookstack')
        rows = [r for r in app['rows'] if r['route'] in set(part)]
        mine = {}
        for q in ('queries', 'rows_examined'):
            def cat(r):
                c = r['q'][q]
                return c['category'] if c['category'] != 'in_denominator' else c['B']['category']
            den = [r for r in rows if cat(r) == 'in_denominator']
            unm = [r for r in rows if cat(r) == 'unmeasured']
            unmarked = [r for r in den if not r['marked']]
            mine[q] = [len(den), len(unm), sum(r['q'][q]['B']['within_min'] for r in den), len(unmarked), sum(r['q'][q]['B']['within_goal'] for r in unmarked)]
        got = json.load(open(os.path.join(a.work, 'part/count-listed.json')))
        theirs = {q: [got['per_quantity'][q]['denominator'], got['per_quantity'][q]['unmeasured'], got['condition_1a']['minimum_all_' + q]['inside'],
                      got['condition_1a']['goal_unmarked_' + q]['denominator'], got['condition_1a']['goal_unmarked_' + q]['inside']] for q in mine}
        ok2 = mine == theirs
    print('2. part of the list = second reading from the unfiltered output:', 'ok' if ok2 else 'FAILED')
    ok &= ok2

    small = os.path.join(a.work, 'small.txt')
    open(small, 'w').write(''.join(x + '\n' for x in sorted(routes)[:3]) + 'GET /not/in/the/estimate\n')
    c3 = count(os.path.join(a.work, 'small'), small)
    ok3 = False
    if c3:
        got = json.load(open(os.path.join(a.work, 'small/count-listed.json')))
        ok3 = got['verdict_rule_B'] == 'undecidable' and got['line_met_on_listed_routes'] is False and got['list_routes_missing_from_estimate'] == ['GET /not/in/the/estimate']
    print('3. short list is undecidable, the line is not met, the missing route is named:', 'ok' if ok3 else 'FAILED')
    ok &= ok3
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
