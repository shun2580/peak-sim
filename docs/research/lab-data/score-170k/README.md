# Verdict outputs of B at 170,000 rows

What the verdict script of `docs/research/lab-data/dryrun-286/judge/` (`judge_b.py`, `order_check.py`, committed in `53825c8` and `62ba6a2`, not changed) wrote when it was run on the record `docs/research/lab-data/ladder-170k/` (committed in `7e20148`) and the prediction `poc/lab/predictions/ladder-prediction-5-b170k.json` (committed in `f52f692`). The script's own check (`selftest.sh`) passed 32 of 32 before it was used. The verdict and its reading are in `docs/research/lab-backtest-5.md`.

| File | What it is |
| --- | --- |
| `b-verdict.json`, `b-verdict.md` | items 1 to 4 at the lowest standard and at the goal, the conditions of "undecidable", the numbers condition 1b is counted with, the limit rps of each scenario |
| `score.json`, `score.md` | the output of the committed scoring tool `poc/lab/score/score-ladder`, unchanged (the rules are in that tool; the script copies none) |
| `order.md` | the order check: the prediction was committed before the first measurement and is unchanged since |
| `seen.txt` | the row counts that appeared as a scale in the earlier predictions, builds, estimates and measurements, written by the person who ran the script (the script does not guess it); used only for the line "not seen before" of condition 1b |

The script was given only the five valid ladders (`s-1-170000` to `s-5-170000`). The first, stopped run of s-3 (`s-3-170000-INVALID-20261119/`) was not given to it and is not used for pass or fail.

Command (from the repository root; `<ladders>` is a directory holding the five valid ladder directories):

```bash
python3 -I docs/research/lab-data/dryrun-286/judge/judge_b.py --prediction poc/lab/predictions/ladder-prediction-5-b170k.json --ladder-dir <ladders> --out <out> --captured-max 30000 --seen seen.txt
python3 -I docs/research/lab-data/dryrun-286/judge/order_check.py --out <file> --prediction poc/lab/predictions/ladder-prediction-5-b170k.json --ladder-dir <ladders>
```
