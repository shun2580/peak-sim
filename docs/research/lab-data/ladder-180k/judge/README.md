# Verdict of the sixth B (180,000 rows): outputs of the two readers

The verdict of B (spec 7.2 and 7.4 B, with 7.4.5) was made from the raw step records of `../run-2-20261122/` by **two readers who did not see each other's work**, and the two agreed on every figure. The prediction is `poc/lab/predictions/ladder-prediction-6-b180k.json` as committed in `79e38f2` (SHA-256 `be0076be321139ebe87b762e971b621875d14c0f1be0130d39e6be64eaab3808`; the file at HEAD is the same; no copy of it is kept here). The first run's `../s-1-180000/` (void) was not used.

**Result: pass at the goal.** 37 steps measured, all valid, 29 usable (10% to 90%), limit rps measured in 5 of 5 scenarios, not undecidable. Item 1: 0.866 pt against 33.351 pt for the naive estimate. Item 2: 0.866 pt. Item 3: 37 of 37 agree, 0 misses. Item 4: 5 of 5 within ±10% (-3.0, -2.0, -3.2, -1.4, -4.4%). Condition 1b counted as met (6.000 times the capture's largest size of 30,000; a count not seen before). The text of the record is `docs/decisions.md` D241.

## The first reader

| File | What it is |
| --- | --- |
| `b/b-verdict.json`, `b/b-verdict.md`, `b/score/` | Output of `docs/research/lab-data/dryrun-286/judge/judge_b.py`, which runs the committed `poc/lab/score/score-ladder`. Command: `python3 -I judge_b.py --prediction poc/lab/predictions/ladder-prediction-6-b180k.json --ladder-dir docs/research/lab-data/ladder-180k/run-2-20261122 --out b --captured-max 30000 --seen seen-counts.txt` |
| `conditions/conditions.json`, `conditions/conditions.md` | Output of `conditions.py` (same directory) with `--a docs/research/extrapolation-data-4/compare/a-verdict.json` (the extrapolation test of D235; **A was not measured again here**) and `--b b/b-verdict.json` |
| `judge_b.stdout` | The one line the script printed (the output directory in it is written relative; the script printed an absolute path) |
| `judge-scripts.sha256` | SHA-256 of `judge_b.py` and `conditions.py` as used |
| `seen-counts.txt` | The 14 counts that had appeared as a scale before this B (from D239 and the earlier predictions). 180,000 is not in it. The script does not guess this list; the reader writes it |
| `rebuild_b.py`, `rebuild.json`, `step-table.md` | A second, separate computation by the first reader that uses neither `judge_b.py` nor `poc/lab/score`: it reads each step's `record.json` and the prediction file and applies the rules of 7.2 and 7.4 B. Its figures equal the script's. `step-table.md` is the 37 steps (measured, predicted, naive, usable, preparation in the record and in the prediction file) |

Where the scripts come from: `judge_b.py` and `conditions.py` are the committed scripts of an earlier rehearsal (`53825c8`, `62ba6a2`; SHA-256 of `judge_b.py` starts `e4297c2d`, of `conditions.py` `ed5fce8f`), **used unchanged** (`git diff HEAD` over their directory is empty). The rules are in `poc/lab/score/`; the scripts copy and change none.

## The second reader

`second-reader/second_reader_b.py` is a computation written by the second reader without a judge script. It takes the run directory, the prediction file, the directory of the calibration records (for the naive basis: `cal-2`) and an output file. `second_reader_b.json` is its output. It solves the predicted limit itself (the point utilisation is linear in rps) and gets the file's value; it also checks that the steps' own validity flag and its own validity test never disagree (0 steps) and that the preparation in each step's plan equals the prediction file (0 differences).

## What the two agree on

Usable steps 29; scenarios with a measured limit 5; not undecidable; item 1 0.8657 pt against 33.3511 pt; item 2 0.8657 pt; item 3 37 of 37 (the same with "at or above" and "above" the threshold); item 4 within ±10% in 5 of 5 with the same five relative differences; the eight steps outside 10% to 90% are the same (s-3 0.025, 0.035, 0.45; s-4 0.15; s-5 0.025, 0.035, 0.05, 0.7). s-3 at 0.45 is 90.0085%, above the 90% line by 0.0085 points.

## Order of the prediction and the first measurement

Estimate commit `9fe817a` 2026-11-21 20:22:41, prediction commit `79e38f2` 20:24:36, first start (`../run-2-20261122/chain-first-start.txt`, the void run) 20:27:29, second start (`../run-2-20261122/chain-files/chain-log.txt`) 2026-11-22 00:08:12. The earliest `started_at` among the 37 steps is 00:08:46. Each step's record names the commit of the prediction in the copy where it was measured (20:24:47), also before the measurement.

## Preparation

`warmup_sec` is 573 s in all 37 steps' `record.json` and `plan.json`, and 573 s in all 75 stages (5 scenarios times 15 steps) of the prediction file. The preparation of this run differs from the earlier runs' 60 s, so the earlier B results are not set beside this one (7.4.5).
