#!/usr/bin/env python3
"""How long the measurements of the fifth round will take, from the windows and waits of the rules and from the times of the earlier records.

  python3 -I estimate_length.py --out <file.md> [--rows-b 170000] [--rows-a 240000]

Reads committed records only (the B ladder of 90,000 rows, the builds of 70,000 to 300,000 rows, the A runs of 300,000 rows); touches no database,
no app and no clock. Every figure comes from a record or from a rule of the spec; a premise is written as a premise. Nothing here is a prediction
of a result, and nothing here uses a figure of a dry run.
"""
import argparse, datetime, glob, json, math, os, statistics, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..', '..', '..'))
LAB = os.path.join(ROOT, 'docs/research/lab-data')
STEPS = [0.025, 0.035, 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0]
T = datetime.datetime.fromisoformat


def window(rps):
    """The smallest multiple of 40 / rps seconds that is at least 300 seconds (spec 7.2), rounded to a whole second as the records are."""
    unit = 40.0 / rps
    return int(round(math.ceil(300.0 / unit - 1e-9) * unit))


def hm(sec):
    sec = int(round(sec))
    return '%d h %02d min' % (sec // 3600, (sec % 3600) // 60)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', required=True)
    ap.add_argument('--rows-b', type=int, default=170000)
    ap.add_argument('--rows-a', type=int, default=240000)
    a = ap.parse_args()

    # --- B: what the 90,000-row record shows about a step
    over, waits, gaps, win_ok = [], [], [], True
    per_scenario_start_end = []
    limits90 = {}
    for p in sorted(glob.glob(os.path.join(LAB, 'ladder-90k/*/ladder.json'))):
        L = json.load(open(p))
        rs = L['rungs']
        for r in rs:
            dur = (T(r['ended_at']) - T(r['started_at'])).total_seconds()
            w = r['end_of_step']['longest_after_window_ms'] / 1000.0
            over.append(dur - r['window_sec'] - w)
            waits.append(w)
            win_ok = win_ok and abs(window(r['decided_rps']) - r['window_sec']) <= 1
        per_scenario_start_end.append((L['scenario'], T(rs[0]['started_at']), T(rs[-1]['ended_at'])))
    per_scenario_start_end.sort()
    for i in range(len(per_scenario_start_end) - 1):
        g = (per_scenario_start_end[i + 1][1] - per_scenario_start_end[i][2]).total_seconds()
        if g < 600:
            gaps.append(g)
    ov_min, ov_med, ov_max = min(over), statistics.median(over), max(over)
    wait_max = max(waits)
    gap = sorted(gaps)[len(gaps) // 2] if gaps else 35
    wins = [window(x) for x in STEPS]

    def cum(k, ov, w):
        return sum(wins[i] + ov + w for i in range(k))
    L = ['# Length of the fifth round, estimated from the rules and the earlier records\n',
         'Written by `judge/estimate_length.py` from committed records. It is an estimate of time, not of any result. No figure of a dry run is used.\n',
         '## B at %s rows (s-1 to s-5, 15 steps each)\n' % format(a.rows_b, ',')]
    L.append('Step time = the window + the part outside the window + the wait at the end of the step. The window is the smallest multiple of 40 / rps seconds that is at least 300 seconds '
             '(spec 7.2); the window rule reproduces the recorded `window_sec` of all %d steps of the 90,000-row B to within 1 second (the tool rounds a fraction of a second its own way): **%s**. '
             'The part outside the window (the discarded first minute and the set-up) was %.0f to %.0f s over those %d steps (median %.0f s). '
             'The wait at the end of a step is the time the load tool waits for requests still running; in the 90,000-row B it was 0 in %d steps and at most %.0f s (the largest). '
             'At %s rows with a 1,800-second wait setting it can be as long as 1,800 s per step. The gap between two scenarios (the app and web containers recreated) was %.0f s (median of %d gaps).\n' % (
                 len(over), 'yes' if win_ok else 'NO', ov_min, ov_max, len(over), ov_med, sum(1 for w in waits if w == 0), wait_max, format(a.rows_b, ','), gap, len(gaps)))
    L.append('| Step k | rps | window (s) | time to the end of step k, shortest (median outside part, no wait) | with the largest outside part and the 90,000-row largest wait (%.0f s) | upper bound (largest outside part, wait 1,800 s) |\n| --- | --- | --- | --- | --- | --- |' % wait_max)
    for k in range(1, 16):
        L.append('| %d | %g | %d | %s | %s | %s |' % (k, STEPS[k - 1], wins[k - 1], hm(cum(k, ov_med, 0)), hm(cum(k, ov_max, wait_max)), hm(cum(k, ov_max, 1800))))
    L.append('')
    L.append('A scenario stops after the first step whose database utilization is 80%% or more, or whose intended rps did not come out (spec 7.2), so the number of steps of each scenario depends on its limit rps, which the prediction gives (T4). '
             'With the prediction\'s limit rps per scenario, the time of B is the sum of the table\'s column at each scenario\'s stop step, plus %.0f s per gap between scenarios (4 gaps).\n' % gap)
    # the premise case: limit rps proportional to 1 / rows, taken from the 90,000-row B
    lim = {'s-1': 0.42213, 's-2': 0.25602, 's-3': 1.0215, 's-4': 0.26257, 's-5': 0.92792}
    ks, total_steps = {}, 0
    for s, v in lim.items():
        l170 = v * 90000.0 / a.rows_b
        k = next((i + 1 for i, x in enumerate(STEPS) if x > l170), 15)
        ks[s] = (l170, k)
        total_steps += k
    case1 = sum(cum(ks[s][1], ov_med, 0) for s in ks) + 4 * gap
    case1_hi = sum(cum(ks[s][1], ov_max, wait_max) for s in ks) + 4 * gap
    k90 = {'s-1': 9, 's-2': 8, 's-3': 12, 's-4': 8, 's-5': 11}
    case0 = sum(cum(k, ov_med, 0) for k in k90.values()) + 4 * gap
    case0_hi = sum(cum(k, ov_max, wait_max) for k in k90.values()) + 4 * gap
    longest = sum(cum(15, ov_max, 1800) for _ in range(5)) + 4 * gap
    longest_nowait = sum(cum(15, ov_max, 0) for _ in range(5)) + 4 * gap
    L.append('Three conditions, to bracket the time before the prediction exists (they are conditions, not predictions):\n')
    L.append('1. **Every scenario stops where it stopped at 90,000 rows** (steps 9, 8, 12, 8, 11 = 48 steps; the time of that record\'s steps was 10 h 34 min): **%s** with the median outside part and no wait, **%s** with the largest outside part and the largest wait seen then.' % (hm(case0), hm(case0_hi)))
    L.append('2. **Premise: the limit rps is inversely proportional to the row count**, taken from the measured limits of the 90,000-row B (0.422, 0.256, 1.022, 0.263, 0.928 rps; not verified at %s rows; the third round\'s limits at 300,000 rows could not be used because that round was undecidable). '
             'The limits would be %s rps, and the stop steps %s (%d steps): **%s** with the median outside part and no wait, **%s** with the largest outside part and the largest wait seen then.' % (
                 format(a.rows_b, ','), ', '.join('%s %.3f' % (s, ks[s][0]) for s in ks), ', '.join('%s step %d' % (s, ks[s][1]) for s in ks), total_steps, hm(case1), hm(case1_hi)))
    L.append('3. **No scenario stops early** (all 15 steps in all 5 scenarios, which needs every limit above 5 rps): **%s** without waits, **%s** as an upper bound with a 1,800-second wait at every step. Not expected; written as the bound.\n' % (hm(longest_nowait), hm(longest)))
    L.append('A step that is invalid (the intended rps did not come out) also stops its scenario, so a scenario can end earlier than its limit; the stop at a break (the stop mark) and the later resume add the wait between sessions and are not counted here. Where a route is taken out of the allocation at %s rows (spec 7.4.4) the load of a scenario and so its limit rps change, and the stop steps change with them.\n' % format(a.rows_b, ','))

    # --- the building of the B database
    fills = {}
    for sc in (70000, 90000, 150000, 200000, 300000):
        pth = glob.glob(os.path.join(LAB, 'build-*%dk' % (sc // 1000), 'build-db-output.txt')) or glob.glob(os.path.join(LAB, 'build-70k-50k', 'build-db-%d-output.txt' % sc))
        if pth:
            d = json.loads(open(pth[0]).read().splitlines()[0]) if open(pth[0]).read().lstrip().startswith('{') else None
            if d is None:
                d = json.loads(next(x for x in open(pth[0]) if x.lstrip().startswith('{')))
            fills[sc] = (d['fill_seconds'], d['total_seconds'])
    L.append('### Building the B database (BookStack, the three tables of the lab chain)\n')
    L.append('Recorded build seconds (`build-db-output.txt` of each count): ' + '; '.join('%s rows: fill %.2f s, total %.2f s' % (format(k, ','), v[0], v[1]) for k, v in sorted(fills.items())) + '.')
    lo, hi = min(v[1] for k, v in fills.items() if k >= 90000 and k <= 200000), max(v[1] for k, v in fills.items() if k >= 150000 and k <= 300000)
    L.append('%s rows lies between the 150,000 count (%.0f s) and the 200,000 count (%.0f s) of those records; the estimate is **about %d to %d seconds** (a few minutes), plus the check of the counts and `ANALYZE TABLE` (a second or two at 150,000; `analyze-timing.tsv`). The fill time is close to linear in the count over these records; that is read from them, not assumed.\n' % (
        format(a.rows_b, ','), fills[150000][1], fills[200000][1], fills[150000][1], fills[200000][1]))

    # --- A
    a3 = os.path.join(ROOT, 'docs/research/extrapolation-data-3/measured')
    fx = json.load(open(os.path.join(a3, 'fixtures/summary.json')))['meta']['seconds']
    bs = json.load(open(os.path.join(a3, 'bookstack/summary.json')))['meta']['seconds']
    ratio = a.rows_a / 300000.0
    L.append('## A at %s rows (the verification app and BookStack, every route, the way of the third run)\n' % format(a.rows_a, ','))
    L.append('The third run (300,000 rows) is the only record of the whole measure in this way. Its recorded seconds (`measured/*/summary.json`, `meta.seconds`, the build included): the verification app %d s (%s); BookStack %d s (%s), of which the build of the measuring database was 4,056.73 s (`measured/README.md`). '
             'The three extra cold laps of the 22 marked routes (`variance/`, a second database at the same time) took 19,894 s (%s), group builds included. '
             'In that run the cold-once run of BookStack and the laps ran side by side on separate databases (`order_record.bookstack.txt`).\n' % (fx, hm(fx), bs, hm(bs), hm(19894)))
    L.append('At %s rows, **premise: the time scales with the row count (a factor of %.2f from 300,000), except the requests that end at the 600-second limit, which do not scale** '
             '(the third run had 1 request over the limit on each application, and `POST /books/{bookSlug}/copy` ran at about 610 s in each of its 3 laps; these stay as they are). On that premise:\n' % (format(a.rows_a, ','), ratio))
    L.append('| Part | 300,000-row record | %s rows, premise above |\n| --- | --- | --- |' % format(a.rows_a, ','))
    L.append('| Verification app, build and measure | %s | about %s |' % (hm(fx), hm(fx * ratio)))
    L.append('| BookStack, build of the measuring database | 1 h 08 min (4,056.73 s) | about %s |' % hm(4056.73 * ratio))
    L.append('| BookStack, cold-once measure (without the build) | %s | about %s |' % (hm(bs - 4056.73), hm((bs - 4056.73) * ratio)))
    L.append('| BookStack, three extra laps of the marked routes (side by side with the cold-once measure) | %s | about %s |\n' % (hm(19894), hm(19894 * ratio)))
    ser = fx * ratio + 4056.73 * ratio + (bs - 4056.73) * ratio + 19894 * ratio
    par = fx * ratio + max(4056.73 * ratio + (bs - 4056.73) * ratio, 19894 * ratio)
    par2 = max(fx * ratio, 0) + 0
    L.append('**Shortest** (the verification app first, then BookStack\'s cold-once measure and the laps side by side as the third run did): about **%s**. '
             '**Longest** (everything one after the other, no overlap): about **%s**. Both on the premise above and with no stop at a break; a resume repeats the measure from the first route of the interrupted run (the third run lost %s that way).\n' % (
                 hm(par), hm(ser), hm(T('2026-11-16T20:00:56+09:00').timestamp() - T('2026-11-16T19:06:43+09:00').timestamp())))
    L.append('The build of the verification app\'s measuring database at %s rows is inside its recorded %d s above. The A builds are of every table (`a-build/`), which is why they take far longer than the B build.\n' % (format(a.rows_a, ','), fx))
    L.append('## What this does not say\n')
    L.append('- It is an estimate of time. It does not say whether B or A will pass, and uses no figure of a dry run.')
    L.append('- Premise 2 of B (limit inversely proportional to the row count) and the A scaling are premises. The prediction of T4 replaces premise 2: its limit rps per scenario gives the stop steps, and the table above gives the time.')
    L.append('- The wait at the end of a step at %s rows is the figure least known: at 90,000 rows it was at most %.0f s, and the 1,800-second setting allows far more for the ZIP export.' % (format(a.rows_b, ','), wait_max))
    open(a.out, 'w').write('\n'.join(L) + '\n')
    print('estimate_length: written to ' + a.out)
    return 0


if __name__ == '__main__':
    sys.exit(main())
