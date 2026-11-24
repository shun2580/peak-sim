#!/usr/bin/env python3
"""Make the list of the routes whose data seems to have arrived, from the output of a capture.

  python3 -I make_arrived_list.py --capture-dir <capture output dir> --out <dir> [--expect-complete 57 --expect-arrived 19]

Reads only `capture.json` and `routes.jsonl` of the capture. Nothing is measured and no rule is changed.

  1. The routes whose estimate can complete: the routes of capture.json whose status is `captured`, and every statement type of which has a
     count fit with status `ok` and at least one scale at which rows were read. This is the capture-side half of the estimator's own test of
     `complete` (poc/runtime/src/Estimate/RowEstimator.php, route() and type(): status not `captured`, count fit not ok, no rows read).
     The other half (the EXPLAIN reads) can only be known when the estimate is made; the counting script checks the list against the estimate.
  2. The routes among them that carry the capture's mark "data may not have arrived" (`data_not_arriving_suspected` in routes.jsonl) are
     taken out. The mark is the capture's; it is not added or removed here.
  3. What is left is the list: `list-arrived.tsv` (and `list-arrived.txt`, one `METHOD /uri` per line, the form the estimate uses).
     The routes taken out are in `list-marked-out.tsv` with the capture's own words for the mark.

If the numbers are not the expected ones, nothing is written and the script exits 4: the list is not fixed up.
"""
import argparse, hashlib, json, os, sys


def sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        h.update(fh.read())
    return h.hexdigest()


def read_jsonl(path):
    out = []
    with open(path, encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line:
                out.append(json.loads(line))
    return out


def capture_side_complete(route):
    """(True, '') if the capture-side conditions of a complete estimate hold, else (False, why)."""
    if route.get('status') != 'captured':
        return False, 'capture status %s' % route.get('status')
    for ty in route.get('statement_types') or []:
        fit = ty.get('count_fit') or {}
        if fit.get('fit_status') != 'ok' or any(k not in fit for k in ('a', 'b', 'k')):
            return False, 'statement %s: count fit %s' % (ty.get('id'), fit.get('fit_status', 'missing'))
        read_any = False
        for v in (ty.get('per_scale') or {}).values():
            if isinstance(v, dict) and 'count' in v and v.get('rows_sum') is not None and int(v['count']) - int(v.get('unread') or 0) > 0:
                read_any = True
        if not read_any:
            return False, 'statement %s: no rows read at any scale' % ty.get('id')
    return True, ''


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--capture-dir', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--expect-complete', type=int, default=57)
    ap.add_argument('--expect-arrived', type=int, default=19)
    a = ap.parse_args()

    cap_path = os.path.join(a.capture_dir, 'capture.json')
    rows_path = os.path.join(a.capture_dir, 'routes.jsonl')
    cap = json.load(open(cap_path, encoding='utf-8'))
    rows = read_jsonl(rows_path)
    verdict_of = {}
    for r in rows:
        verdict_of[(r['method'], r['route'])] = r

    complete, not_complete = [], []
    for r in cap['routes']:
        ok, why = capture_side_complete(r)
        (complete if ok else not_complete).append((r, why))

    arrived, marked_out, problems = [], [], []
    for r, _ in complete:
        k = (r['method'], r['uri'])
        row = verdict_of.get(k)
        if row is None:
            problems.append('%s %s: in capture.json but not in routes.jsonl' % k)
            continue
        entry = {'key': '%s %s' % k, 'method': k[0], 'uri': k[1], 'verdict': row['verdict'],
                 'captured_scales': ','.join(str(s) for s in r['captured_scales'])}
        if row.get('data_not_arriving_suspected'):
            entry['mark'] = str(row.get('data_doubt', ''))
            marked_out.append(entry)
        else:
            arrived.append(entry)
    arrived.sort(key=lambda e: e['key'])
    marked_out.sort(key=lambda e: e['key'])

    summary = ('capture routes %d; capture-side complete %d (expected %d); marked out %d; arrived %d (expected %d); '
               'not complete on the capture side %d' % (len(cap['routes']), len(complete), a.expect_complete, len(marked_out),
                                                         len(arrived), a.expect_arrived, len(not_complete)))
    print(summary)
    if problems or len(complete) != a.expect_complete or len(arrived) != a.expect_arrived:
        for p in problems:
            print('problem:', p, file=sys.stderr)
        print('make_arrived_list: the numbers are not the expected ones. Nothing written; the list is not fixed up.', file=sys.stderr)
        return 4

    os.makedirs(a.out, exist_ok=True)
    with open(os.path.join(a.out, 'list-arrived.tsv'), 'w', encoding='utf-8') as f:
        f.write('method\turi\tverdict\tcaptured_scales\n')
        for e in arrived:
            f.write('%s\t%s\t%s\t%s\n' % (e['method'], e['uri'], e['verdict'], e['captured_scales']))
    with open(os.path.join(a.out, 'list-arrived.txt'), 'w', encoding='utf-8') as f:
        for e in arrived:
            f.write(e['key'] + '\n')
    with open(os.path.join(a.out, 'list-marked-out.tsv'), 'w', encoding='utf-8') as f:
        f.write('method\turi\tverdict\tcaptured_scales\tcapture_mark\n')
        for e in marked_out:
            f.write('%s\t%s\t%s\t%s\t%s\n' % (e['method'], e['uri'], e['verdict'], e['captured_scales'], e['mark']))
    with open(os.path.join(a.out, 'list-not-complete.tsv'), 'w', encoding='utf-8') as f:
        f.write('method\turi\tstatus\treason\n')
        for r, why in sorted(not_complete, key=lambda x: (x[0]['uri'], x[0]['method'])):
            f.write('%s\t%s\t%s\t%s\n' % (r['method'], r['uri'], r.get('status'), why))
    with open(os.path.join(a.out, 'list-inputs.sha256'), 'w', encoding='utf-8') as f:
        f.write('%s  capture.json\n%s  routes.jsonl\n' % (sha256(cap_path), sha256(rows_path)))
    with open(os.path.join(a.out, 'list-summary.txt'), 'w', encoding='utf-8') as f:
        f.write(summary + '\n')
    return 0


if __name__ == '__main__':
    sys.exit(main())
