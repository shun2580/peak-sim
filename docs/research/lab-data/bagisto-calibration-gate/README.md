# The calibration gate for the Bagisto lab backtest, run on inputs only

The calibration set of the lab backtest on Bagisto (`docs/simulator-spec.md` section 7.4.7: cal-1 to cal-5, and cal-6 and cal-7 only if the gate
refuses the first five) was put through the calibration gate (`poc/lab/scenarios/calibration-gate`; degree and threshold in decisions D185 and D186) **before any
calibration run was measured**. The gate reads files only: an estimate, the plan of each run and the count of rows of each run. No database, no application and no
lab container was started, and no utilization was read.

The rates of the runs (R_0 for cal-1 to cal-3, R_1 for cal-4, R_2 for cal-5, R_3 for cal-6 and cal-7) are not known until they are searched for in the lab, so
the gate was called over the grid of values the search rule can give (decisions D264, decision 4). **This grid is a reference. It does not decide whether the set
is accepted**: the gate that counts is the one `poc/lab/bin/measure-calibration` calls with the real plans once the rates are found (D264, decision 6).

## Inputs

| What | Value |
| --- | --- |
| Repository state | `124fa09` (the gate and its parts have no change from it) |
| Estimate | made by `bin/estimate` (candidate H1_RANGE, with formulas) from the third capture of Bagisto and the EXPLAIN of the large-count database, with the committed prediction file of the extrapolation test; sha256 `6e8716cc3b9eae5e5aa4f5b0f7a52d1452562eb0380efce6e9e9a8476f7065a3` (made twice; the same bytes) |
| Shared-table copy (D264, decisions 1 and 2) | sha256 `92fd9b7b78a400f6e355c77d7492fa82a204fdb815fa1aaab196c7f4760a194c`; in `route_index`, the `table` of every entry with axis N and table null (119 of 120, all 8 routes of the list among them) is `n_shared`; nothing else is changed (`share-proof.json`: 119 changed lines, every one a `table` line; with those fields set back to null the copy equals the original) |
| Routes | the 8 routes of `docs/research/lab-data/bagisto-route-list/list/selected.tsv`, in its order (`route-list.json`) |
| Counts of rows | cal-1, cal-2, cal-3: 10,000; cal-4: 3,000; cal-5, cal-6, cal-7: 30,000; every N table at the same count (`--tables=n_shared`) |
| Mixes | cal-1 even; cal-2 first four routes 80%; cal-3 routes 1, 3, 5, 7 at 80%; cal-4 and cal-5 even; cal-6 the route ranked 1 at 80% and the other seven 20% together, evenly; cal-7 the same for the route ranked 2 (plans made by `poc/lab/load/make-plan.php`, cal-6 and cal-7 with `--allocation`) |
| Grid | R_0 and R_1 in 1, 2, 4, 8, 16, 32 (doubling from 1); R_2 and R_3 in the stage values 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1, 1.5, 2.2, 3.3, 5 and the geometric-mean step 0.37 (D183) |
| Host when run | 2026-11-25 12:24 to 13:01; memory 15 GiB total, about 10 GiB available; disk 883 GB free |

## Result

| Set | Points | Gate open (exit 0) | Gate refused (exit 14) | Smallest degree over the grid | Largest "smallest degree" |
| --- | --- | --- | --- | --- | --- |
| cal-1 to cal-5 | 504 | **0** | 504 | 0.020855 | 0.318796 (R_0 = 2, R_1 = 8, R_2 = 2.2; the set without cal-4) |
| cal-1 to cal-7 | 7,056 | 338 (4.8%) | 6,718 | 0.021415 | 0.571967 (R_0 = 8, R_1 = 32, R_2 = 0.05, R_3 = 5; the set without cal-4) |

- **cal-1 to cal-5 is refused at every one of the 504 points**, so the set cannot be accepted whatever the rates turn out to be. cal-6 and cal-7 are added, as the
  ruling says.
- **cal-1 to cal-7 is not refused everywhere, and it is not accepted everywhere.** It opens at 338 of 7,056 points. The set that decides is the one without cal-4
  (3,391 of the 7,056 points) or the one without cal-6 (2,640); at the points where the gate opens, they are 165 and 173 of the 338.
- Where it opens: R_0 at most 8 (0 of 2,352 points at 16 and 32); R_1 at least 2 (0 of 1,176 at 1; 21 at 2; 70 at 4; 93 at 8 and 16; 61 at 32); R_3 at least 0.3
  (0 of 2,520 points at 0.05, 0.07, 0.1, 0.15 and 0.2; 6 at 0.3; 11 at 0.37; 12 at 0.45; 40 to 66 at each value from 0.7 to 5). R_2 matters little (4 to 32 of 504 points at each value).
- The nearest points to the line, at one set of rates (R_0 = 2, R_1 = 8, R_2 = 2.2): R_3 = 2.2 opens (0.502335) and R_3 = 0.3 is refused (0.360296); full outputs
  are in `reference-points/`.
- The largest degree over the whole grid is 0.571967, 0.07 above the threshold of 0.5, and the points that open are close to the line. The real rates, once found,
  may put the set on either side of it. The record does not say which.

## What follows from it

- This grid is not a pass or a fail of the calibration set. After the rates are searched for (D183 and D186 rules), `measure-calibration` calls the gate with the real
  plans and its answer is recorded before any calibration run is measured (D264, decision 6). If that gate refuses cal-1 to cal-7, nothing is measured and the
  case goes to the maintainer (D258 (b)).
- The shared-table copy is the one to use for the gate, the dry-run prediction and the prediction (D264, decision 2). `--tables` has two meanings: `n_shared` for the
  estimate, the gate and `bin/simulate`, and the real 123 tables for the guard `check-tables` and the build.

## Files

| File | What it is |
| --- | --- |
| `share_n_table.py` | makes the shared-table copy by a byte-level edit of the table lines and writes the proof: `python3 -I share_n_table.py <estimate.json> <copy.json> <proof.json>` |
| `share-proof.json` | the proof (both sha256, the number of lines changed, the routes changed) |
| `route-list.json` | the 8 routes in the form `make-plan.php` reads |
| `run_gate_grid.sh` | makes the plans of the grid and calls the gate at every point: `JOBS=4 run_gate_grid.sh <work dir> <estimate dir> <route-list.json> 5` (or `7`); on set 5 it gives, line for line, `grid-cal-1-to-5.tsv` |
| `grid-cal-1-to-5.tsv`, `grid-cal-1-to-7.tsv` | the gate's exit code, smallest degree and the run left out in the smallest set, at every grid point |
| `reference-points/` | the gate's full output at four points |
