# Pencil-and-paper trials for the options write-up (not a result, not a test)

Four small scripts that put the three ways of mending the 4th item of the back-test (the limit rps) on the records that were
already seen (90,000, 170,000 and 300,000 rows). They are arithmetic on existing tables.

**None of this is a result and none of it is a test.** A number printed here is a candidate form laid on records that have
already been looked at; spec 7.4.4 calls that a check of the fit, not a test. A test needs a row count not yet seen, a form
committed before the measurement, and a new measurement. The scripts measure nothing, call nothing in the lab, change no
prediction, estimate, calibration, formula, rule, mark or score, and write no file (output goes to stdout only).

## How to run

From anywhere, with only the standard library:

```
python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/trials/<script>.py
```

Paths to the records are relative to the repository root and are found from the place of the script. Every table is read by
column name, never by position (the 170,000 tables do not have the same column order as the 90,000 ones). The tables used are
the main tables of this folder: `../90k/`, `../170k/` and `../300k/` (`step_table.csv`, `route_table.csv`, `step_extra.csv`),
plus, where a script needs the DB CPU readings or the schedule of the requests, the step records under `ladder-90k/` and
`ladder-170k/` (`rung-*/cpu.txt`, `rung-*/plan.json`). The first, stopped run of 170,000 scenario 3
(`s-3-170000-INVALID-20261119`) is not read.

## The scripts

| Script | Trials it gives | Reads | Prints |
| --- | --- | --- | --- |
| `trial_a1_shape.py` | **A-0** the limit rps of the present proportional formula against the measured limit; **A-1** a one-parameter cap (smooth minimum, hyperbola) fitted on two counts and applied to the third, and fitted on one count and applied to another | `step_table.csv` of the three counts | per scenario the measured and predicted limit and the error; the fitted parameter and the error on the held-out count |
| `trial_a2_a3_window.py` | **A-2** the predicted work that falls inside the window when each ZIP request spends its work evenly over its own time; **A-3** the closed form `1 - (T - P)^2 / (2 T W)` (T the ZIP time, P = 60 s the preparation, W the window), with T fixed at the lowest step and with T at each step | `step_table.csv`, `route_table.csv`, `step_extra.csv` (90,000), the ZIP row of `route_table.csv` (170,000), `rung-*/plan.json` | per scenario the measured limit and the error of the baseline, A-2 and the two forms of A-3 |
| `trial_b1_recount.py` | **B-1** what a recount of the existing records gives: the DB CPU of the whole step (idle taken off) over the predicted work of all requests started in it, minus 1 | `step_table.csv`, `route_table.csv`, `rung-*/cpu.txt`, `rung-*/plan.json` | how many steps can be recounted, the range and median of the ratio, which steps are 80% measured or more (none can be recounted), the recounted steps at 60% predicted or more |
| `trial_c_rule.py` | **C** two example lines for "can no longer keep up": requests finished after the window over requests sent, at 10% and 20% | `step_table.csv` (90,000 and 170,000) | per scenario the counts and shares by step, and where each line is first crossed against the measured limit rps |

## What to know when reading the output

- **300,000 rows** is in `trial_a1_shape.py` only. Its tool was an older one (it cut the requests still running at the end of a
  step) and its main tables hold no measured time of a ZIP request, so A-2, A-3 and B-1 leave it out; its limit is measured in one
  scenario only. Read it as a reference.
- **The time of a ZIP request** is the mean over the step (k6 in-flight counts, Little's formula, 1 s resolution), so it contains
  whatever the load stretched it by. For 170,000 scenario 1 at 0.3 rps the main table's value is 206.4 s (the reading is a range,
  202.9 to 211.6 s, because k6 stops updating the per-route count while a route is finishing; see `../170k/README.md`). The
  0.3 rps step is the upper end of the interval where the curve crosses 80% in that scenario, but taking 211.6 s instead of
  206.4 s moves the A-2 and A-3 limit of that scenario by less than 0.1 percentage point; the other scenarios are not affected.
- **A-2 uses one mean ZIP time per step**, not the time of each request: the tables do not hold the time of each request. A form
  that pairs the starts and the ends of each ZIP request one by one gives somewhat different numbers (a smaller error of the limit
  rps at 90,000), so do not read the A-2 figures to the last digit.
- **A-3 has no fitted parameter**; its form is fixed by arithmetic. It assumes the DB work of a ZIP request is spread evenly over
  its own time, which no record shows (there is no per-request DB time at 170,000 or 300,000 rows).
- **B-1** counts the whole step, so it needs the next step's first reading; the last step of each scenario cannot be counted, and
  that is where the measured utilisation is 80% or more. What B-1 says about the ratio is limited to the steps before saturation.
- **C**: the 10% and 20% lines were put down after part of the records had been seen. They are examples, not a rule.
