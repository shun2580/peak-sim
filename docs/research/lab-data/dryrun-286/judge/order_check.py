#!/usr/bin/env python3
"""Order check: the prediction was committed before the first measurement, and has not changed since.

  python3 -I order_check.py --out <file> --prediction <file> [--prediction <file> ...] \
      [--ladder-dir <dir>] [--order-record <file> ...] [--repo <dir>]

  --prediction   a committed prediction or estimate file (one or more; relative to the repository). The commit that last changed it is
                 read with `git log`, and `git diff --quiet <that commit> HEAD -- <file>` shows it is unchanged since
  --ladder-dir   B: a directory of <scenario>/ladder.json; the earliest `started_at` of any step is the first measurement; the commit each
                 step recorded (`prediction.commit`) must be the commit read here
  --order-record A: an order record whose lines are `<name> <ISO time> ...` and whose names end `_start` (the tool writes them);
                 the earliest time is a first measurement
At least one of --ladder-dir and --order-record is needed. Every time is compared as an absolute time (the zone is read).
Writes a short Markdown file and prints the verdict line. Exit 0 when the order holds, 1 when it does not, 2 wrong usage.
Reads files and the git history only.
"""
import argparse, datetime, glob, json, os, subprocess, sys


def iso(s):
    return datetime.datetime.fromisoformat(s)


def git(repo, *args):
    r = subprocess.run(['git', '-C', repo] + list(args), capture_output=True, text=True)
    return r.returncode, r.stdout.strip()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', required=True)
    ap.add_argument('--prediction', action='append', required=True)
    ap.add_argument('--ladder-dir')
    ap.add_argument('--order-record', action='append', default=[])
    ap.add_argument('--repo', default=os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', '..', '..'))
    a = ap.parse_args()
    if not a.ladder_dir and not a.order_record:
        print('order_check: give --ladder-dir or --order-record', file=sys.stderr)
        return 2
    repo = os.path.abspath(a.repo)
    ok, L = True, ['# Order check (order_check.py)\n']
    commits = {}
    for f in a.prediction:
        rc, line = git(repo, 'log', '-1', '--format=%H %cI', '--', f)
        if rc != 0 or not line:
            ok = False
            L.append('- `%s`: **not committed** (no commit touches it)' % f)
            continue
        h, t = line.split(' ', 1)
        rc2, _ = git(repo, 'diff', '--quiet', h, 'HEAD', '--', f)
        unchanged = rc2 == 0
        ok = ok and unchanged
        commits[f] = (h, iso(t))
        L.append('- `%s`: last changed in `%s` at %s; unchanged since: **%s**' % (f, h[:7], t, 'yes' if unchanged else 'NO'))
    firsts = []
    if a.ladder_dir:
        recorded = set()
        for p in sorted(glob.glob(os.path.join(a.ladder_dir, '*', 'ladder.json'))):
            for r in json.load(open(p))['rungs']:
                firsts.append(('step %s rung %s' % (r['scenario'], r['rung']), iso(r['started_at'])))
                recorded.add((r.get('prediction') or {}).get('commit'))
        known = {h for h, _ in commits.values()}
        match = recorded <= known and bool(recorded)
        ok = ok and match
        L.append('- the commit named in every step record: %s; read from the history: %s; **%s**' % (
            ', '.join(sorted(str(x)[:7] for x in recorded)), ', '.join(sorted(x[:7] for x in known)), 'agree' if match else 'DISAGREE'))
    for p in a.order_record:
        for line in open(p):
            parts = line.split()
            if len(parts) >= 2 and parts[0].endswith('_start'):
                firsts.append((parts[0], iso(parts[1])))
    if not firsts:
        ok = False
        L.append('- no first measurement was found in the records')
    else:
        what, first = min(firsts, key=lambda x: x[1])
        L.append('- earliest measurement in the records: %s at %s' % (what, first.isoformat()))
        for f, (h, t) in commits.items():
            gap = (first - t).total_seconds()
            good = gap > 0
            ok = ok and good
            L.append('- `%s` was committed %s the first measurement (gap %.0f seconds): **%s**' % (f, 'before' if good else 'AFTER or at', abs(gap), 'ok' if good else 'NOT ok'))
    L.append('\n**Order: %s.**' % ('holds' if ok else 'does NOT hold'))
    open(a.out, 'w').write('\n'.join(L) + '\n')
    print('order_check: %s -> %s' % ('holds' if ok else 'does NOT hold', a.out))
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
