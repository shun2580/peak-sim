#!/usr/bin/env python3
"""Find the tries that the tool cut at its time limit, by the text of the error output, and give the judge a copy of the records that says so.

  python3 -I timeout_scan.py --out <dir> --measure <measure dir> --variance <variance dir> [--variance <variance dir> ...]
  python3 -I timeout_scan.py --selftest

The rule (one sentence, the lord's ruling of 2026-11-24 18:45; D255 has the same words):

  A try whose `stderr.log` holds the text `the request ran longer than 600 seconds` in even one place (the text of RequestTimeout,
  poc/runtime/src/Run/Requester.php:232) counts as a timeout (D126b), whatever the class and the detail of its record say.

What it does, and nothing else:

  1. variance: every directory under `--variance` that holds both `stderr.log` and `attempt.jsonl` is one try (one route, one lap).
     The try is a hit if its `stderr.log` holds the text. No person picks a try.
  2. measure (the cold 1 of all routes): `bin/measure` keeps no per-try stderr file. It keeps the record of every attempt in
     `attempts.jsonl` (poc/runtime/src/Measure/Driver.php:321 writes the tail of a failed runner's stderr into `detail`, and only there;
     Attempt.php has no other field for it). So the same reading is applied to what the measure does keep: the `detail` of each record,
     any `stderr.log` under the measure directory (read as for variance), and, with no route to put it on, the run-level
     `measure.stderr.log` / `measure.stdout.log` next to the measure directory (the number of lines with the text is printed; a
     route cannot be named from it). The output says which of these exist, so that nobody reads "0 hits" as "stderr was read" when it
     was not kept.
  3. writes to `<out>`:
       timeout-trials.tsv        one row per hit: source, route (`METHOD /uri`), phase, lap, db, group, attempts in the record, class and
                                 status of the record as written, the file read. Sorted, so two runs give the same bytes.
       timeout-scan-summary.txt  the counts and the list of what was readable (measure: yes/no per source).
       records-timeout/          a copy of the records for the judge: `measure/attempts.jsonl` and, per variance directory N (in the
                                 order given), `variance<N>/<same relative path>/attempt.jsonl`. In a hit try every record gets
                                 `class: "timeout"`, the old class kept in `class_raw` and the old detail in `detail_raw`, and `detail`
                                 starts with the text. Every other field and every other line is copied as written. Feed
                                 `records-timeout/measure` and `records-timeout/variance1[,variance2...]` to judge_a.py / count_listed.py.
     The original records are read only and never written. judge_a.py and compare_test2.py are not changed.

Exit codes: 0 scanned; 2 wrong usage or an input that is not there.
"""
import argparse, hashlib, json, os, sys, tempfile

NEEDLE = 'the request ran longer than 600 seconds'


def has_needle_file(path):
    """True if the file holds the text in even one place. Read as bytes, so a bad byte cannot hide it."""
    try:
        data = open(path, 'rb').read()
    except OSError:
        return False
    return NEEDLE.encode() in data


def count_needle_lines(path):
    if not os.path.isfile(path):
        return None
    return sum(1 for raw in open(path, 'rb') if NEEDLE.encode() in raw)


def read_records(path):
    """(prefix, record or None, raw line) per line. A line is read from its first `{"route"` as compare_test2.py does."""
    out = []
    with open(path, 'rb') as f:
        for raw in f:
            line = raw.decode('utf-8', 'replace')
            i = line.find('{"route"')
            if i >= 0:
                try:
                    out.append((line[:i], json.loads(line[i:]), line))
                    continue
                except ValueError:
                    pass
            out.append((None, None, line))
    return out


def mark(rec):
    """The record as the judge should read it: a timeout. The old values are kept beside, nothing is dropped."""
    r = dict(rec)
    r['class_raw'] = rec.get('class')
    r['detail_raw'] = rec.get('detail')
    r['class'] = 'timeout'
    old = rec.get('detail')
    r['detail'] = NEEDLE if old in (None, '') else NEEDLE + ' | ' + str(old)
    return r


def write_copy(src, dst, hit):
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    with open(dst, 'w', encoding='utf-8') as out:
        for prefix, rec, line in read_records(src):
            if rec is None or not hit(rec):
                out.write(line)
            else:
                out.write(prefix + json.dumps(mark(rec), separators=(',', ':')) + '\n')


def trial_info(tdir, recs):
    info = {}
    tj = os.path.join(tdir, 'trial.json')
    if os.path.isfile(tj):
        try:
            info = json.load(open(tj, encoding='utf-8'))
        except ValueError:
            info = {}
    r0 = recs[0] if recs else {}
    route = info.get('route') or ('%s %s' % (r0.get('method', ''), r0.get('route', '')))
    return route, info


def scan_variance(vdir, label, rows, copies):
    n_tries = n_hit = 0
    for root, dirs, files in os.walk(vdir):
        dirs.sort()
        if 'stderr.log' in files and 'attempt.jsonl' in files:
            n_tries += 1
            att = os.path.join(root, 'attempt.jsonl')
            recs = [r for _, r, _ in read_records(att) if r is not None]
            hit = has_needle_file(os.path.join(root, 'stderr.log'))
            rel = os.path.relpath(att, vdir)
            copies.append((att, os.path.join(label, rel), hit))
            if hit:
                n_hit += 1
                route, info = trial_info(root, recs)
                rows.append(('variance', route, str(info.get('phase', '')), str(info.get('lap', '')), str(info.get('db', '')),
                             str(info.get('group', '')), str(info.get('attempts', len(recs))),
                             ','.join(sorted({str(r.get('class')) for r in recs})), ','.join(sorted({str(r.get('status')) for r in recs})),
                             os.path.join(os.path.basename(vdir.rstrip('/')), os.path.dirname(rel), 'stderr.log')))
        elif 'attempt.jsonl' in files:
            # a record file with no stderr.log beside it: copied as it is, and said in the summary
            att = os.path.join(root, 'attempt.jsonl')
            copies.append((att, os.path.join(label, os.path.relpath(att, vdir)), False))
    return n_tries, n_hit


def scan_measure(mdir, rows, copies, notes):
    att = os.path.join(mdir, 'attempts.jsonl') if os.path.isdir(mdir) else mdir
    base = os.path.dirname(att)
    recs = [r for _, r, _ in read_records(att) if r is not None]
    hit_routes = set()
    for r in recs:
        if NEEDLE in str(r.get('detail') or ''):
            hit_routes.add((r.get('method', ''), r.get('route', '')))
    per_trial_stderr = []
    for root, dirs, files in os.walk(base):
        if 'stderr.log' in files:
            per_trial_stderr.append(os.path.join(root, 'stderr.log'))
    for (m, route) in sorted(hit_routes):
        rs = [r for r in recs if (r.get('method', ''), r.get('route', '')) == (m, route)]
        rows.append(('measure', '%s %s' % (m, route), 'cold', '1', '', '', str(len(rs)),
                     ','.join(sorted({str(r.get('class')) for r in rs})), ','.join(sorted({str(r.get('status')) for r in rs})),
                     os.path.basename(att) + ' (detail)'))
    copies.append((att, os.path.join('measure', 'attempts.jsonl'), lambda r: NEEDLE in str(r.get('detail') or '')))
    notes.append('measure: %d records in %s; the text is in the detail of %d route(s).' % (len(recs), os.path.basename(att), len(hit_routes)))
    notes.append('measure: per-try stderr files under the measure directory: %d%s.' % (
        len(per_trial_stderr), '' if per_trial_stderr else ' (bin/measure keeps none: the cold 1 of a route is read from its record only)'))
    for sp in per_trial_stderr:
        notes.append('measure: %s holds the text: %s (not attributed to a route by this script; look at it by hand)' % (sp, 'yes' if has_needle_file(sp) else 'no'))
    parent = os.path.dirname(base.rstrip('/'))
    for name in ('measure.stderr.log', 'measure.stdout.log'):
        n = count_needle_lines(os.path.join(parent, name))
        notes.append('measure: run-level %s: %s' % (name, 'not found' if n is None else '%d line(s) with the text (no route can be named from it)' % n))
    return len(recs), len(hit_routes)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out')
    ap.add_argument('--measure')
    ap.add_argument('--variance', action='append', default=[])
    ap.add_argument('--selftest', action='store_true')
    a = ap.parse_args()
    if a.selftest:
        return selftest()
    if not a.out or not a.measure or not a.variance:
        print('timeout_scan: --out, --measure and at least one --variance are needed', file=sys.stderr)
        return 2
    for p in [a.measure] + a.variance:
        if not os.path.exists(p):
            print('timeout_scan: not found: %s' % p, file=sys.stderr)
            return 2
    rows, copies, notes = [], [], []
    n_rec, n_mhit = scan_measure(a.measure, rows, copies, notes)
    for i, v in enumerate(a.variance, 1):
        label = 'variance%d' % i
        nt, nh = scan_variance(v, label, rows, copies)
        notes.append('variance %d: %s: %d tries with a stderr.log, %d hit.' % (i, os.path.basename(v.rstrip('/')), nt, nh))
    rows.sort()
    os.makedirs(a.out, exist_ok=True)
    with open(os.path.join(a.out, 'timeout-trials.tsv'), 'w', encoding='utf-8') as f:
        f.write('\t'.join(('source', 'route', 'phase', 'lap', 'db', 'group', 'attempts_in_record', 'class_as_written', 'status_as_written', 'file_read')) + '\n')
        for r in rows:
            f.write('\t'.join(r) + '\n')
    cdir = os.path.join(a.out, 'records-timeout')
    for src, rel, hit in copies:
        if callable(hit):
            write_copy(src, os.path.join(cdir, rel), hit)
        else:
            write_copy(src, os.path.join(cdir, rel), (lambda rec: True) if hit else (lambda rec: False))
    with open(os.path.join(a.out, 'timeout-scan-summary.txt'), 'w', encoding='utf-8') as f:
        f.write('rule: a try whose stderr.log holds "%s" in even one place counts as a timeout (D126b).\n' % NEEDLE)
        f.write('script sha256: %s\n' % hashlib.sha256(open(os.path.abspath(__file__), 'rb').read()).hexdigest())
        f.write('hits: %d (measure %d, variance %d)\n' % (len(rows), n_mhit, len(rows) - n_mhit))
        for n in notes:
            f.write(n + '\n')
    print('timeout_scan: %d hit(s) -> %s' % (len(rows), a.out))
    return 0


def selftest():
    """Made-up records: a try with the text under a record of class exception, one with it far down the file, one without, one
    measure record whose detail holds it. Checks the hits, the copy, and that the original is not written."""
    ok = True
    with tempfile.TemporaryDirectory() as td:
        v, m = os.path.join(td, 'v'), os.path.join(td, 'm')
        for name, text in (('01_A', 'x\nlocal.ERROR: ' + NEEDLE + ' (View: y)\n'), ('02_B', 'noise\n' * 5000 + '[previous exception] ' + NEEDLE + '\n'), ('03_C', 'fine\n')):
            d = os.path.join(v, 'g1', 'plain_lap1', name)
            os.makedirs(d)
            open(os.path.join(d, 'stderr.log'), 'w').write(text)
            rec = {'route': '/' + name, 'method': 'GET', 'scale': 1, 'axis': 'N', 'attempt': 1, 'status': 500, 'class': 'exception', 'detail': None}
            open(os.path.join(d, 'attempt.jsonl'), 'w').write(json.dumps(rec) + '\n')
            json.dump({'route': 'GET /' + name, 'phase': 'plain', 'lap': 1, 'db': 1, 'group': 1, 'attempts': 1}, open(os.path.join(d, 'trial.json'), 'w'))
        os.makedirs(m)
        open(os.path.join(m, 'attempts.jsonl'), 'w').write(
            json.dumps({'route': '/m1', 'method': 'GET', 'scale': 1, 'axis': 'N', 'attempt': 2, 'status': 500, 'class': 'exception', 'detail': 'exit 1: ' + NEEDLE}) + '\n' +
            json.dumps({'route': '/m2', 'method': 'GET', 'scale': 1, 'axis': 'N', 'attempt': 2, 'status': 200, 'class': 'ok', 'detail': None}) + '\n')
        before = {p: open(p, 'rb').read() for p in [os.path.join(m, 'attempts.jsonl')] + [os.path.join(r, f) for r, _, fs in os.walk(v) for f in fs]}
        out = os.path.join(td, 'out')
        sys.argv = ['timeout_scan.py', '--out', out, '--measure', m, '--variance', v]
        rc = main_args(out, m, v)
        rows = [l.split('\t') for l in open(os.path.join(out, 'timeout-trials.tsv')).read().splitlines()[1:]]
        got = sorted(r[1] for r in rows)
        checks = [('exit 0', rc == 0), ('hits are A, B, m1', got == ['GET /01_A', 'GET /02_B', 'GET /m1']),
                  ('original unchanged', all(open(p, 'rb').read() == b for p, b in before.items()))]
        c = [json.loads(l) for l in open(os.path.join(out, 'records-timeout', 'variance1', 'g1', 'plain_lap1', '01_A', 'attempt.jsonl'))]
        checks.append(('copy of A is timeout, old class kept', c[0]['class'] == 'timeout' and c[0]['class_raw'] == 'exception' and c[0]['status'] == 500))
        c = [json.loads(l) for l in open(os.path.join(out, 'records-timeout', 'variance1', 'g1', 'plain_lap1', '03_C', 'attempt.jsonl'))]
        checks.append(('copy of C is as written', c[0]['class'] == 'exception' and 'class_raw' not in c[0]))
        c = [json.loads(l) for l in open(os.path.join(out, 'records-timeout', 'measure', 'attempts.jsonl'))]
        checks.append(('measure m1 timeout, m2 ok', c[0]['class'] == 'timeout' and c[1]['class'] == 'ok'))
        for name, res in checks:
            print('%s: %s' % ('ok  ' if res else 'FAIL', name))
            ok = ok and res
    return 0 if ok else 1


def main_args(out, m, v):
    rows, copies, notes = [], [], []
    scan_measure(m, rows, copies, notes)
    scan_variance(v, 'variance1', rows, copies)
    rows.sort()
    os.makedirs(out, exist_ok=True)
    with open(os.path.join(out, 'timeout-trials.tsv'), 'w') as f:
        f.write('header\n')
        for r in rows:
            f.write('\t'.join(r) + '\n')
    for src, rel, hit in copies:
        write_copy(src, os.path.join(out, 'records-timeout', rel), hit if callable(hit) else ((lambda rec: True) if hit else (lambda rec: False)))
    return 0


if __name__ == '__main__':
    sys.exit(main())
