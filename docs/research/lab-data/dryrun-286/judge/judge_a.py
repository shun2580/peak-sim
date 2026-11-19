#!/usr/bin/env python3
"""Verdict script for A (the extrapolation test): the verdict of each application, the four numbers of condition 1a, and the misses of condition 3.

  python3 -I judge_a.py --out <dir> \
      --app <name> <estimate.json> <marked.json> <measure dir or attempts.jsonl> <variance dir> [--app ...]

The arguments after --app are the ones `docs/research/extrapolation-data-2/compare_test2.py` takes (one --app per application; the
estimate and marked files are the committed ones). What it does:

  1. checks that the comparison tool is byte for byte the file the third run used (SHA-256 below). A different file stops the script (exit 3):
     the rule of the verdict is in that file and is not copied or changed here (D212: rule B decides, rule A is shown);
  2. runs it (reading files only; no database, no app, no clock);
  3. reads `compare.json` and writes `a-verdict.json` and `a-verdict.md`: per application the verdict (pass, fail, undecidable) and the
     reasons, the shares by group, the four numbers of condition 1a (D153, with the scope of D215: per application), and the routes outside
     the goal (condition 3: a miss is a route with queries or rows examined outside the goal under rule B; routes not measured are counted apart).

Exit 0 whatever the verdict; 2 wrong usage; 3 the tool is not the pinned file or did not run.
"""
import argparse, hashlib, json, os, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL_SHA256 = '95da6a9b77bdc5c43a1455c9c1ac9b4f11cb0e966aa1cfeac4e42220e382bb8b'
GATING = ('queries', 'rows_examined')
LABEL = {'queries': 'queries', 'rows_examined': 'rows examined'}
GOAL = {'queries': '±2%', 'rows_examined': '±10%'}
MIN = {'queries': '±5%', 'rows_examined': '±20%'}


def repo_root():
    out = subprocess.run(['git', '-C', HERE, 'rev-parse', '--show-toplevel'], capture_output=True, text=True)
    if out.returncode != 0:
        sys.exit('judge_a: not inside a git work tree')
    return out.stdout.strip()


def sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        h.update(fh.read())
    return h.hexdigest()


def need(den, inside):
    """max(0, ceil(0.8 * den) - inside), in whole numbers (D153)."""
    return -((-8 * den) // 10), max(0, -((-8 * den) // 10) - inside)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', required=True)
    ap.add_argument('--app', nargs=5, action='append', metavar=('NAME', 'ESTIMATE', 'MARKED', 'MEASURE', 'VARIANCE'), required=True)
    ap.add_argument('--tool', help='path of compare_test2.py (default: the one in extrapolation-data-2 of this repository)')
    a = ap.parse_args()
    tool = a.tool or os.path.join(repo_root(), 'docs/research/extrapolation-data-2/compare_test2.py')
    if not os.path.isfile(tool) or sha256(tool) != TOOL_SHA256:
        print('judge_a: the comparison tool is not the pinned file (%s). Stopping.' % tool, file=sys.stderr)
        return 3
    os.makedirs(a.out, exist_ok=True)
    r1 = os.path.join(a.out, 'R1')
    cmd = ['python3', '-I', tool, '--out', r1]
    for app in a.app:
        cmd += ['--app'] + app
    run = subprocess.run(cmd, capture_output=True, text=True)
    if run.returncode != 0 or not os.path.isfile(os.path.join(r1, 'compare.json')):
        print('judge_a: the comparison tool did not run (exit %d):\n%s%s' % (run.returncode, run.stdout, run.stderr), file=sys.stderr)
        return 3
    cmp_ = json.load(open(os.path.join(r1, 'compare.json')))
    names = [x[0] for x in a.app]

    result = {'rows': cmp_.get('at'), 'tool_sha256': TOOL_SHA256, 'rule': cmp_.get('rule'), 'apps': {}}
    L = ['# A verdict (judge_a.py)\n',
         'Rows %s. The comparison tool is `extrapolation-data-2/compare_test2.py` (SHA-256 `%s`, checked before the run). %s.\n' % (
             cmp_.get('at'), TOOL_SHA256, cmp_.get('rule'))]
    for name in names:
        summ = cmp_['scopes'][name]['summary']
        b, a_rule = summ['B'], summ['A']
        sh = b['shares']
        app = next(x for x in cmp_['apps'] if x['app'] == name)
        one_a = {}
        for q in GATING:
            den, inside = sh['unmarked'][q]['denominator'], sh['unmarked'][q]['within_goal']
            n, s = need(den, inside)
            one_a['goal_unmarked_' + q] = {'inside': inside, 'denominator': den, 'needed': n, 'short': s}
            den, inside = sh['all'][q]['denominator'], sh['all'][q]['within_min']
            n, s = need(den, inside)
            one_a['minimum_all_' + q] = {'inside': inside, 'denominator': den, 'needed': n, 'short': s}
        undecidable = b['verdict'] in ('undecidable', 'indeterminate')  # the tool says "indeterminate"; the spec says undecidable
        misses, unmeasured, no_estimate = [], [], []
        for r in app['rows']:
            cats = {q: r['q'][q]['B']['category'] if 'B' in r['q'][q] else r['q'][q]['category'] for q in GATING}
            if any(c == 'unmeasured' for c in cats.values()):
                unmeasured.append(r['route'])
            elif any(c == 'no_estimate' for c in cats.values()):
                no_estimate.append(r['route'])
            out_q = {q: r['q'][q]['B']['relative_error'] for q in GATING
                     if cats[q] == 'in_denominator' and not r['q'][q]['B']['within_goal']}
            if out_q:
                misses.append({'route': r['route'], 'marked': bool(r['marked']), 'outside_goal': out_q})
        result['apps'][name] = {
            'verdict': 'undecidable' if undecidable else b['verdict'], 'tool_verdict_word': b['verdict'], 'reasons': b['reasons'], 'rule_A_verdict': 'undecidable' if a_rule['verdict'] in ('undecidable', 'indeterminate') else a_rule['verdict'], 'rule_A_reasons': a_rule['reasons'],
            'shares': sh, 'condition_1a': one_a, 'condition_1a_short_all_zero': (not undecidable) and all(v['short'] == 0 for v in one_a.values()),
            'condition_3_misses': misses, 'condition_3_misses_marked': sum(1 for m in misses if m['marked']),
            'condition_3_misses_unmarked': sum(1 for m in misses if not m['marked']),
            'not_measured_with_estimate': unmeasured, 'no_estimate_routes': no_estimate,
        }
        L.append('## %s\n' % name)
        L.append('**Verdict (rule B): %s.** %s\n' % (result['apps'][name]['verdict'].upper(), '; '.join(b['reasons'])))
        L.append('Rule A, shown and not deciding: %s. %s\n' % (result['apps'][name]['rule_A_verdict'], '; '.join(a_rule['reasons'])))
        L.append('| Condition 1a (D153; per application, D215) | Inside | Denominator | Needed | Short |\n| --- | --- | --- | --- | --- |')
        for q in GATING:
            v = one_a['goal_unmarked_' + q]
            L.append('| Goal, unmarked routes, %s (%s) | %d | %d | %d | %d |' % (LABEL[q], GOAL[q], v['inside'], v['denominator'], v['needed'], v['short']))
        for q in GATING:
            v = one_a['minimum_all_' + q]
            L.append('| Lowest standard, all routes, %s (%s) | %d | %d | %d | %d |' % (LABEL[q], MIN[q], v['inside'], v['denominator'], v['needed'], v['short']))
        L.append('')
        if undecidable:
            L.append('The test is undecidable here: no number short is given (D153).\n')
        L.append('Condition 1a for this application is %s.\n' % (
            'met (all four short numbers are 0)' if result['apps'][name]['condition_1a_short_all_zero'] else 'not met' if not undecidable else 'undecidable'))
        L.append('Condition 3: **%d routes outside the goal** (%d marked, %d unmarked; marks are the marks of test A). Not measured, with an estimate: %d; '
                 'no estimate: %d (counted apart, not misses).\n' % (len(misses), result['apps'][name]['condition_3_misses_marked'],
                                                                      result['apps'][name]['condition_3_misses_unmarked'], len(unmeasured), len(no_estimate)))
        if misses:
            L.append('| Route | Marked | Quantity outside the goal and its relative error |\n| --- | --- | --- |')
            for m in sorted(misses, key=lambda m: m['route']):
                L.append('| `%s` | %s | %s |' % (m['route'], 'yes' if m['marked'] else 'no',
                                                  '; '.join(('%s %.2f%%' % (LABEL[q], 100 * e)) if e is not None else ('%s: measured 0, estimate not below 0.5' % LABEL[q]) for q, e in m['outside_goal'].items())))
            L.append('')
        if unmeasured:
            L.append('Not measured, with an estimate: ' + ', '.join('`%s`' % x for x in sorted(unmeasured)) + '.\n')
    if 'both apps' in cmp_['scopes']:
        both = cmp_['scopes']['both apps']['summary']['B']
        bw = 'undecidable' if both['verdict'] in ('undecidable', 'indeterminate') else both['verdict']
        result['both_apps_not_for_the_verdict'] = {'verdict': bw, 'reasons': both['reasons']}
        L.append('## Both applications together (shown beside the verdicts; not used for them, D71 and D215)\n')
        L.append('%s. %s\n' % (bw, '; '.join(both['reasons'])))
    json.dump(result, open(os.path.join(a.out, 'a-verdict.json'), 'w'), indent=1, sort_keys=True)
    open(os.path.join(a.out, 'a-verdict.json'), 'a').write('\n')
    open(os.path.join(a.out, 'a-verdict.md'), 'w').write('\n'.join(L) + '\n')
    print('judge_a: ' + '; '.join('%s %s' % (n, result['apps'][n]['verdict']) for n in names) + ' -> ' + a.out)
    return 0


if __name__ == '__main__':
    sys.exit(main())
