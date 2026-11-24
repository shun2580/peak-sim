#!/usr/bin/env python3
"""List the marked routes whose cold try of the all-route measure failed, and make the copy of the records the judge reads for rule (a).

  python3 -I cold_fail_marked.py --out <dir> --compare <compare_test2.py> --marked <marked.json> --at <rows> \
      --measure <measure dir or attempts.jsonl> [--repeated <routes list>] [--variance <dir> ...]

The ruling (the lord, 2026-11-24 18:50): a marked route whose cold try of the all-route measure failed (500 and the like) and whose
repeat later gave an ok is counted as NOT MEASURED (a). The count of the records as written (b) is shown beside it and decides nothing.
The reason is D126b: the measure side shows a timeout only if its text was kept, so "no timeout seen" does not mean "no timeout".

What it does, and nothing else:

  1. reads the measure records and, for each route of the marked list, makes the same try the comparison tool makes
     (`make_try` of compare_test2.py, imported, not copied) and reads its class with the same tool (`failure_class`). A route whose
     try is not ok is listed. A marked route that has no record at the size is listed apart (never tried). No rule is made here.
  2. writes `cold-fail-marked.tsv` (route, class as the tool reads it, class/status/detail as written, the file read, whether the
     route is in the list of routes repeated, if one is given) and `cold-fail-marked-summary.txt` (the count, the names, the
     SHA-256 of the comparison tool and of this script).
  3. if `--variance` is given: copies each `attempt.jsonl` under it to `<out>/records-a/variance<N>/<same relative path>`. In the records
     of a listed route every record gets `class: "cold_failed_unmeasured"`, the old class in `class_raw`, the old detail in
     `detail_raw`; every other record and line is copied as written. The comparison tool reads that class as not ok, so the route has
     no cold value from the repeat and is not measured (a). The measure records are copied unchanged to `<out>/records-a/measure/`.
     Feed `records-a/measure` and `records-a/variance1[,variance2...]` to judge_a.py for (a), and the originals for (b).
     If the variance copy comes from timeout_scan.py (`records-timeout/variance<N>`), pass that directory here: the two copies chain.

  The comparison tool prints the class of a rewritten try as `other:cold_failed_unmeasured`; that is this copy, not a new rule.
  Records are read only. compare_test2.py and judge_a.py are not changed.

Exit codes: 0 done; 2 wrong usage or an input that is not there.
"""
import argparse, hashlib, importlib.util, json, os, sys

CLASS = 'cold_failed_unmeasured'


def sha256(path):
    return hashlib.sha256(open(path, 'rb').read()).hexdigest()


def load_tool(path):
    spec = importlib.util.spec_from_file_location('compare_test2', path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def copy_records(tool, src, dst, listed):
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    n = 0
    with open(dst, 'w', encoding='utf-8') as out:
        for raw in open(src, 'rb'):
            line = raw.decode('utf-8', 'replace')
            i = line.find('{"route"')
            if i < 0:
                out.write(line)
                continue
            try:
                rec = json.loads(line[i:])
            except ValueError:
                out.write(line)
                continue
            if tool.key_of(rec) in listed:
                r = dict(rec)
                r['class_raw'], r['detail_raw'], r['class'] = rec.get('class'), rec.get('detail'), CLASS
                out.write(line[:i] + json.dumps(r, separators=(',', ':')) + '\n')
                n += 1
            else:
                out.write(line)
    return n


def run(a):
    tool = load_tool(a.compare)
    marked = [r['route'] for r in json.load(open(a.marked, encoding='utf-8')).get('routes', [])]
    path = os.path.join(a.measure, 'attempts.jsonl') if os.path.isdir(a.measure) else a.measure
    by = {}
    for rec in tool.read_jsonl(path):
        by.setdefault(tool.key_of(rec), []).append(rec)
    repeated = None
    if a.repeated:
        repeated = {l.strip() for l in open(a.repeated, encoding='utf-8') if l.strip()}
    rows, never, ok = [], [], 0
    for route in marked:
        t = tool.make_try(by.get(route, []), a.at, path)
        if t is None:
            never.append(route)
        elif t['ok']:
            ok += 1
        else:
            rec = max([x for x in by[route] if int(x.get('scale', -1)) == a.at and x.get('axis', 'N') == 'N' and x.get('class') != 'ok'],
                      key=lambda x: int(x.get('attempt', 0)))
            rows.append((route, t['class'], str(rec.get('class')), str(rec.get('status')), str(rec.get('detail') or '')[:120].replace('\t', ' ').replace('\n', ' '),
                         'yes' if repeated is not None and route in repeated else ('no' if repeated is not None else 'n/a'), os.path.basename(path)))
    os.makedirs(a.out, exist_ok=True)
    with open(os.path.join(a.out, 'cold-fail-marked.tsv'), 'w', encoding='utf-8') as f:
        f.write('\t'.join(('route', 'class_by_comparison_tool', 'class_as_written', 'status_as_written', 'detail_as_written', 'in_repeated_list', 'file_read')) + '\n')
        for r in sorted(rows):
            f.write('\t'.join(r) + '\n')
    listed = {r[0] for r in rows}
    ncopy = 0
    for i, v in enumerate(a.variance, 1):
        for root, dirs, files in os.walk(v):
            dirs.sort()
            if 'attempt.jsonl' in files:
                src = os.path.join(root, 'attempt.jsonl')
                ncopy += copy_records(tool, src, os.path.join(a.out, 'records-a', 'variance%d' % i, os.path.relpath(src, v)), listed)
    copy_records(tool, path, os.path.join(a.out, 'records-a', 'measure', 'attempts.jsonl'), set())
    with open(os.path.join(a.out, 'cold-fail-marked-summary.txt'), 'w', encoding='utf-8') as f:
        f.write('marked routes: %d; cold try ok: %d; cold try failed (listed): %d; no record at %d rows: %d\n' % (len(marked), ok, len(rows), a.at, len(never)))
        f.write('listed (%d):\n' % len(rows))
        for r in sorted(rows):
            f.write('  %s  [%s; written: %s %s; in repeated list: %s]\n' % (r[0], r[1], r[2], r[3], r[5]))
        if never:
            f.write('marked routes with no record at the size (not listed): %s\n' % ', '.join(never))
        f.write('variance records rewritten for (a): %d (directories: %d)\n' % (ncopy, len(a.variance)))
        f.write('compare_test2.py sha256: %s\n' % sha256(a.compare))
        f.write('script sha256: %s\n' % sha256(os.path.abspath(__file__)))
    print('cold_fail_marked: %d of %d marked routes listed -> %s' % (len(rows), len(marked), a.out))
    return 0


def selftest():
    import tempfile
    here = os.path.dirname(os.path.abspath(__file__))
    compare = os.path.join(here, '..', '..', '..', 'extrapolation-data-2', 'compare_test2.py')
    ok = True
    with tempfile.TemporaryDirectory() as td:
        def rec(route, attempt, cls, status=200, detail=None):
            return json.dumps({'route': route, 'method': 'GET', 'scale': 10, 'axis': 'N', 'attempt': attempt, 'status': status, 'class': cls, 'detail': detail})
        open(os.path.join(td, 'attempts.jsonl'), 'w').write('\n'.join([
            rec('/a', 2, 'ok'), rec('/b', 2, 'runner_failed', None, 'exit 9: x'), rec('/c', 1, 'exception', 500), rec('/c', 2, 'exception', 500)]) + '\n')
        json.dump({'routes': [{'route': 'GET /a'}, {'route': 'GET /b'}, {'route': 'GET /c'}, {'route': 'GET /d'}]}, open(os.path.join(td, 'm.json'), 'w'))
        v = os.path.join(td, 'v', 'g1', 't1')
        os.makedirs(v)
        open(os.path.join(v, 'attempt.jsonl'), 'w').write('\n'.join([rec('/b', 2, 'ok'), rec('/a', 2, 'ok')]) + '\n')
        before = open(os.path.join(v, 'attempt.jsonl'), 'rb').read()
        ns = argparse.Namespace(out=os.path.join(td, 'o'), compare=compare, marked=os.path.join(td, 'm.json'), at=10, measure=td, repeated=None,
                                variance=[os.path.join(td, 'v')])
        rc = run(ns)
        rows = [l.split('\t') for l in open(os.path.join(td, 'o', 'cold-fail-marked.tsv')).read().splitlines()[1:]]
        c = [json.loads(l) for l in open(os.path.join(td, 'o', 'records-a', 'variance1', 'g1', 't1', 'attempt.jsonl'))]
        checks = [('exit 0', rc == 0), ('listed b (runner_failed) and c (exception), not a, not d (no record)', sorted(r[0] for r in rows) == ['GET /b', 'GET /c']),
                  ('copy: b not ok, a as written', c[0]['class'] == CLASS and c[0]['class_raw'] == 'ok' and c[1]['class'] == 'ok'),
                  ('original unchanged', open(os.path.join(v, 'attempt.jsonl'), 'rb').read() == before)]
        for n, r in checks:
            print('%s: %s' % ('ok  ' if r else 'FAIL', n))
            ok = ok and r
    return 0 if ok else 1


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out')
    ap.add_argument('--compare')
    ap.add_argument('--marked')
    ap.add_argument('--at', type=int)
    ap.add_argument('--measure')
    ap.add_argument('--repeated')
    ap.add_argument('--variance', action='append', default=[])
    ap.add_argument('--selftest', action='store_true')
    a = ap.parse_args()
    if a.selftest:
        return selftest()
    if not (a.out and a.compare and a.marked and a.at and a.measure):
        print('cold_fail_marked: --out --compare --marked --at --measure are needed', file=sys.stderr)
        return 2
    for p in [a.compare, a.marked, a.measure] + a.variance + ([a.repeated] if a.repeated else []):
        if not os.path.exists(p):
            print('cold_fail_marked: not found: %s' % p, file=sys.stderr)
            return 2
    return run(a)


if __name__ == '__main__':
    sys.exit(main())
