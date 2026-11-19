#!/usr/bin/env python3
"""Makes made-up records in the shape a dry run writes, to check that the verdict scripts run to the end.

  python3 -I make_fake.py --out <dir>

The records are FAKE: the shapes are copied from the committed records of the third extrapolation test and of the fourth lab backtest,
and the numbers are changed on purpose (some inside the goal, some inside the lowest standard only, some outside, one route that fails,
one that is not measured). They are not records of any measurement and no figure in them is used for anything but this check.
Deterministic: the same call writes the same bytes.

Writes under <dir>:
  b/prediction.json, b/ladders/<id>-30000/ladder.json   B: the 5 scenarios of the fourth round, 2 steps each (the shape of the dry run)
  b-full/prediction.json, b-full/ladders/...             B: the same, every step up to the first one over 80% (the shape of a real run)
  b-none/...                                             B: 2 steps each, all above 90% (no usable step: the means are null)
  a/<app>/estimate.json, marked.json, attempts.jsonl, variance/   A: a few routes of each application
"""
import argparse, copy, json, os, shutil, sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..', '..', '..', '..', '..'))
PRED_B = os.path.join(ROOT, 'poc/lab/predictions/ladder-prediction-4-b90k.json')
PRED_A = os.path.join(ROOT, 'poc/lab/predictions')
MEAS_A = os.path.join(ROOT, 'docs/research/extrapolation-data-3/measured')
FAKE_SCALE = 30000
COMMIT = '0' * 40


def dump(path, obj):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w') as fh:
        json.dump(obj, fh, indent=1, sort_keys=True)
        fh.write('\n')


def fake_b(out, steps_each, boost=1.0):
    pred = json.load(open(PRED_B))
    pred['estimate_at'] = FAKE_SCALE
    dump(os.path.join(out, 'prediction.json'), pred)
    for sc in pred['scenarios']:
        rungs, n = [], 0
        for i, st in enumerate(sc['stages']):
            if steps_each is not None and i >= steps_each:
                break
            # made-up utilisation: the predicted point times a factor that depends on the scenario and the step
            factor = 0.93 + 0.03 * (len(sc['id']) % 3) + 0.02 * (i % 4)
            util = min(1.2, st['utilization']['point'] * factor * boost)
            rungs.append({
                'scenario': sc['id'], 'scale': FAKE_SCALE, 'mix': sc.get('mix'), 'scheme': 'B', 'rung': i + 1,
                'decided_rps': st['rps_total'], 'achieved_rps': st['rps_total'], 'rps_ratio': 1.0, 'rps_valid': True,
                'db_utilization': util, 'db_utilization_percent': 100 * util,
                'started_at': '2026-11-19T14:%02d:00+09:00' % (n % 60), 'ended_at': '2026-11-19T14:%02d:30+09:00' % (n % 60),
                'prediction': {'file': 'fake/prediction.json', 'commit': COMMIT, 'commit_time': '2026-11-19T13:00:00+09:00'},
            })
            n += 1
            if steps_each is None and util > 0.8:
                break
        dump(os.path.join(out, 'ladders', '%s-%d' % (sc['id'], FAKE_SCALE), 'ladder.json'),
             {'scenario': sc['id'], 'scale': FAKE_SCALE, 'rungs_measured': len(rungs), 'trial': False, 'rungs': rungs})


def fake_a(out):
    plan = {'fixtures': [(0.0, 'unmarked'), (1.0, 'unmarked'), (1.0, 'unmarked'), (1.04, 'unmarked'), (1.5, 'unmarked')],
            'bookstack': [(0.0, 'unmarked'), (1.0, 'unmarked'), (1.04, 'unmarked'), (1.5, 'unmarked'), (1.0, 'marked'), (1.04, 'marked'),
                          (1.5, 'marked'), ('fail', 'marked')]}
    for app, picks in plan.items():
        est = json.load(open(os.path.join(PRED_A, 'extrapolation-3-%s-estimate.json' % app)))
        marked = json.load(open(os.path.join(PRED_A, 'extrapolation-3-%s-marked.json' % app)))
        marked_names = {r['route'] for r in marked['routes']}
        meas = [json.loads(line[line.index('{"route"'):]) for line in open(os.path.join(MEAS_A, app, 'attempts.jsonl')) if '{"route"' in line]
        measured_keys = {(r['method'] + ' ' + r['route']) for r in meas if r['scale'] == 300000 and r['attempt'] == 2 and r['class'] == 'ok'}
        usable = [r for r in est['routes'] if r['status'] == 'captured' and r.get('complete') and r['route'] in measured_keys]
        chosen = []
        for want, mk in picks:
            pool = [r for r in usable if ((r['route'] in marked_names) == (mk == 'marked')) and r not in [c[0] for c in chosen]
                    and r['queries'] > 0 and r['rows_examined'] > 0]
            if not pool:
                sys.exit('make_fake: no route left for ' + app + ' ' + mk)
            chosen.append((pool[0], want))
        keep = {c[0]['route']: c[1] for c in chosen}
        est2 = copy.deepcopy(est)
        est2['at'] = FAKE_SCALE
        est2['routes'] = [r for r in est['routes'] if r['route'] in keep]
        dump(os.path.join(out, 'a', app, 'estimate.json'), est2)
        marked2 = copy.deepcopy(marked)
        marked2['at'] = FAKE_SCALE
        marked2['routes'] = [r for r in marked['routes'] if r['route'] in keep]
        marked2['marked_count'] = len(marked2['routes'])
        dump(os.path.join(out, 'a', app, 'marked.json'), marked2)
        lines = []
        for r in meas:
            key = r['method'] + ' ' + r['route']
            if r['scale'] != 300000 or key not in keep:
                continue
            r = copy.deepcopy(r)
            r['scale'] = FAKE_SCALE
            w = keep[key]
            if w == 'fail':
                r.update({'status': 500, 'class': 'exception', 'detail': 'fake: a made-up failure'})
            else:
                for q in ('queries', 'rows_examined'):
                    r[q] = r[q] * w
            lines.append(json.dumps(r))  # the key order is kept: the comparison tool reads a line from its first {"route"
        os.makedirs(os.path.join(out, 'a', app, 'variance'), exist_ok=True)
        with open(os.path.join(out, 'a', app, 'attempts.jsonl'), 'w') as fh:
            fh.write('\n'.join(lines) + '\n')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', required=True)
    a = ap.parse_args()
    if os.path.exists(a.out) and os.listdir(a.out):
        sys.exit('make_fake: the output directory is not empty: ' + a.out)
    fake_b(os.path.join(a.out, 'b'), 2)
    fake_b(os.path.join(a.out, 'b-full'), None)
    fake_b(os.path.join(a.out, 'b-none'), 2, 1000.0)   # every step above 90%: no usable step, so the means are null
    fake_a(a.out)
    print('make_fake: made-up records written to ' + a.out)
    return 0


if __name__ == '__main__':
    sys.exit(main())
