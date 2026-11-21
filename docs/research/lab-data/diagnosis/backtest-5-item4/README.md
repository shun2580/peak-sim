# Item 4 of the 5th round of B: where the limit rps comes out low (records only)

Records for the question "why is the predicted limit rps lower than the measured one in all five scenarios?" at 90,000, 170,000 and 300,000 rows. Nothing here is a prediction, a measurement or a score, and nothing here changes the prediction, the estimate, the calibration, the formula, the rules of the mark and of pass and fail, the specification or any earlier record or verdict. No measurement was made and no lab container was touched: every number is read from records that already exist.

The reasoning is in `docs/research/lab-backtest-5-causes.md`. This file says what each directory holds and how the allocation is built.

## Files

| path | what it is |
|---|---|
| `90k/`, `170k/`, `300k/` | the tables of the single counts (`step_table.csv`, `route_table.csv`, `README.md`, the script that writes them, and where the records give more, `step_extra.csv`, `route_table_measured.csv`, `tail_table.csv`) |
| `conditions/` | the conditions of the measurements side by side, the yardstick of the utilisation, and what the database CPU readings cover after each window (`step_cpu_coverage.csv`) |
| `dryrun/` | the same tables for the dry run at 30,000 rows. Reference only: not a test and not a score |
| `allocate.py` | reads the tables above and the records of the steps, and writes the three files below |
| `allocation_by_step.csv` | one row per recorded step: the pieces of the difference, the factor kappa, the time of a request alone, and the trial series |
| `allocation.json` | every number of `allocation-tables.md` and the checks that the script runs |
| `allocation-tables.md` | the tables the document quotes |
| `trials/` | the scripts of the trials of the three proposals (arithmetic on the records; not results and not tests) |

Run (from anywhere; the paths are found from the file's place; nothing but these three files is written):

```
python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/allocate.py
```

It exits non-zero if one of its checks fails (more than two thousand: for every step, the arrivals of the plan equal the counts of the tables; the work of the counts equals the table's; the measured utilisation rebuilt from the CPU time of the window equals the table's; the pieces below add up to the difference).

## The allocation

**Unit.** Points of the 2-core utilisation of the step's own window, so `1 point = 0.02 × window_s CPU seconds` of that step. The difference is `diff = predicted − measured` (positive when the prediction is above the measurement). A piece is its contribution to `diff`; the pieces of a step add up to it. Shares are of the sum of `diff` over the steps of a band, and are printed only where that sum is 3 points or more in size (below that a share is not stable). A negative share, or one above 100%, is printed as it is.

**Pieces.** For a step with predicted point `P`, work of the requests it sent `W_sent` (the table's `pred_work_cpu_s`), and measured window CPU time `M_work`:

| piece | what it is | how it is read |
|---|---|---|
| `d_rounding` | the prediction is made at the decided rps; the step sent a whole number of requests. `P` minus `W_sent` set against the window | arithmetic on the table |
| `a_estimate` | `(1 − kappa) × W_sent` in points: the database CPU time of the requests that were sent, as measured, against the work the prediction gave them. `kappa` is one factor for the whole step | from the step's whole database CPU time (below) |
| `edge` | work of requests sent in the window that is done after it (spill), less work of requests sent in the discarded minute that is done inside it (carry-in). Split into `edge_unloaded` and `edge_extra` | spill read from the records; carry-in from kappa |
| `idle` | the database's CPU when nothing is asked of it, taken off the measured side (it is in the measurement and not in the prediction) | arithmetic; rate from the steps with no request running at the window's end |

**kappa.** For a step whose next step is in the same scenario, the database's CPU from the step's first reading to the next step's first reading is the work of every request the step sent, the discarded minute's included, plus idle (at 90,000 and 170,000 rows the database was idle at the start of every step, and the wait was never cut, so no earlier work is in it; the script checks this: the work found at the start of a step is between −10.5 and +0.6 CPU seconds at 90,000 rows and between −1.4 and +0.4 at 170,000 rows). The requests sent in the discarded minute are counted from the plan (`plan.json`: first arrival and period of each route), and their predicted work is `W_disc`. Then `kappa = (CPU of the step, idle off) ÷ (W_sent + W_disc)`. The assumptions: the requests of the discarded minute have the same factor as those of the window, and the count of them is the plan's. With this the carry-in is `kappa × W_disc` less the CPU the database did in the discarded minute, and the pieces add up to `diff` exactly.

**Residual steps.** The last step of a scenario has no next step to read the end of the wait from (nor, at 300,000 rows, a step whose wait the load tool cut). For those, kappa is the median of the same scenario's other steps (or of the count when none is read), and `edge` is what is left of `diff` after the other pieces. Their `edge_extra` is therefore a residual and carries the error of that kappa: the table "Residual steps" of `allocation-tables.md` gives its range over the scenario's kappa values and, where the records give an upper bound of the CPU after the window, the largest value the bound allows (in 4 of the 6 such steps with a bound, the residual spill is above the bound by 6 to 50 CPU seconds, so the piece is unsure by up to about 6 points at the steps of highest load).

**The model of the edge.** In the records a ZIP request draws about the same share of the database in all three counts: the prediction's work of one ZIP request divided by the time that request takes in the steps of low load is 0.476 to 0.490 cores at 90,000 rows, 0.474 to 0.490 at 170,000 rows and 0.468 to 0.481 at 300,000 rows (the time is the longest request of a step whose measured utilisation is below 35%, `summary.json`, median over the steps of the scenario). The model puts the work of each ZIP request evenly over that time, with the arrival times of the plan, and gives `edge_unloaded`: the edge that the length of a request alone gives, with no waiting. `edge_extra` is `edge` less `edge_unloaded`: what a step with a high load adds. The model is an assumption; it is checked where the records give the spill directly at a low load (the table "Model of the edge" of `allocation-tables.md`: 59 steps; the model's edge differs from the record's by a median of 0.00, +0.47 and +0.11 CPU seconds per step at the three counts) and at the start of 87 steps (the work found there is near zero, above).

**The 300,000-row records** were made with an older tool: the load tool waited 30 s at the end of a step and cut what was still running, which went on in the database and was running at the start of the next step (20 of the 30 steps). Their steps are split only where the wait was not cut (10 steps) and, for the others, where the next step's start was clean (5); the rest are given as `diff` less rounding and idle with no split. The work found running at the start of those steps is sized in the last table of `allocation-tables.md` (5.6 to 66.4 CPU seconds in the discarded minute alone).

## What is read and what is assumed

| | read from the records (arithmetic) | assumed |
|---|---|---|
| idle, rounding, the pieces adding up to `diff`, the spill of a step whose wait is read, the CPU of a whole step | yes | |
| `kappa` of such a step | | the factor of the discarded minute's requests equals the window's; their count is the plan's |
| the split of `edge` into `edge_unloaded` and `edge_extra` | | the work of a ZIP request is spread evenly over its time (checked at low load) |
| `kappa` and the split of the last step of a scenario | | the scenario's kappa; the range is given |
| the 300,000-row steps with a cut wait | only the lower bounds | not split |
