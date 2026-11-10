# Lab backtest (B): does the simulator predict the database utilization of a busy period reproduced in the lab?

Status: DONE. Test B did not pass: the lowest standard is not met (item 4, the limit rps), and the goal is not met (items 2 and 4); see section 7.4. Sections 1 to 6 (what is built, calibrated, predicted, measured and how the verdict is decided) were fixed before the result and are not changed by it; sections 7.1 to 7.7 give the calibration, the prediction, the measurement and the verdict as they came out. Every figure in this file is copied from the output of a tool or from a record.

This file is the record of test B of `docs/simulator-spec.md` (version 0.2, sections 7.2 to 7.4). It says what was done in which order, what was measured, and what the numbers can and cannot support. The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file.

What is checked is a **check against a busy period reproduced in the lab**: the prediction is compared with a measurement made in the lab. Nothing here was observed on a real deployment, and no value measured in production appears (spec section 1). Section 6 lists what the lab cannot say.

## 1. The environment

The lab is a closed environment in which BookStack really runs under load, so the prediction has something to be compared with. It is an experiment under `poc/lab/`, not part of the official tool. The way it is built and started is in `poc/lab/README.md`; this section gives what a reader needs to judge the numbers.

| Part | What it is |
| --- | --- |
| Application | BookStack under php-fpm (16 static workers, opcache on), behind nginx. `artisan serve` is not used because it takes one request at a time. The application is mounted read-only and is not modified |
| Database | MySQL 8 (`mysql:8.4.7`). The automatic recalculation of statistics is left at its default, so that the handling of `plan_unstable` statements (D107) has the chance to matter. The buffer pool holds every database of the scenarios |
| Load | k6, in the same closed network. One constant-arrival-rate stream per route, GET routes only |
| CPU allocation | database 2 CPUs (fixed), application 6 CPUs (so that the application does not choke before the database), load tool 2 CPUs, database builder on its own cores |

**Database utilization** is the change in the database container's cgroup CPU time (`usage_usec` of `cpu.stat`) divided by (the length of the kept window in seconds × 2 CPUs). An instantaneous reading is never used. The reading is sampled every few seconds and interpolated linearly at both ends of the kept window; if the file cannot be read, does not cover the window, or goes backwards, no figure is produced (D144).

**Safety conditions** (carried over from the measurement mode, `poc/runtime/README.md` conditions 1 to 6; D146):

1. The network is internal with no published port; a check refuses to start otherwise (a start with a failed check leaves nothing running).
2. Only measurement databases are reachable, with the one grant of the measurement user; every connection of the application is fixed to and checked against the measurement database on every request.
3. Only generated data is used.
4. The application is not changed; the container root is read-only.
5. Side effects are replaced by fakes on every request.
6. Only GET and HEAD are served; every request runs in a transaction that is rolled back, so the number of rows of a table does not change during a load.

**Routes.** The 8 routes are chosen mechanically, before any result: the GET routes of BookStack whose cost the simulator could estimate (spec section 4), ordered by the degree of the static lower bound, largest first, ties broken by the string "method + URI"; the first 8. The list was made by a script and committed before the first load was applied. Commit of the list: `0a756c7` (2026-11-09T16:13:16+09:00). Its hash is recorded in `poc/lab/routes/SHA256SUMS`.

**Table sizes.** All 8 routes share one database, so the tables that scale the routes (`entities`, `images`, `references`, the union of the routes' N tables) are set together to the size of the scenario. Every other table keeps the generator's default size. The estimate used for the prediction was captured in the same way (the same tables grown together, at 100, 1,000 and 10,000 rows) and is not rebuilt afterwards (D149, D151).

## 2. Scenarios

N_0 = 10,000 rows. R_0 is the total rps with which scenario `cal-1` gives a database utilization of 20% to 30%; it was found with the conditions of `cal-1` alone, doubling the rps from 1, and no prediction scenario was measured before R_0 was fixed. Value of R_0: 1 rps (`cal-1` at 1 rps in total gave a database utilization of 23.0922%). `cal-4` has its own row count, so it has its own R_1, found by the same rule with the conditions of `cal-4` alone: 1 rps gave 7.7642%, 2 rps 14.8407% and 4 rps 27.6175%, so R_1 = 4 rps.

| id | Kind | Rows | Total rps | Mix of routes |
| --- | --- | --- | --- | --- |
| cal-1 | calibration | N_0 | R_0 | even over the 8 routes |
| cal-2 | calibration | N_0 | R_0 | first 4 of the list 80%, last 4 20% |
| cal-3 | calibration | N_0 | R_0 | odd positions (1, 3, 5, 7) 80%, even positions 20% |
| cal-4 | calibration | 0.3 × N_0 | R_1 | even over the 8 routes |
| cal-3-orig | record only | N_0 | R_0 | last 4 80%, first 4 20% (not used for calibration) |
| t-1 | prediction | 3 × N_0 | ladder | even |
| t-2 | prediction | 10 × N_0 | ladder | even |
| t-3 | prediction | 10 × N_0 | ladder | first 4 80% |
| t-4 | prediction | N_0 | ladder | even |
| t-5 | prediction | 10 × N_0 | ladder | last 4 80% |

- **Why cal-3 was changed.** The first cal-3 (last 4 routes 80%) was cal-2 with left and right exchanged, so that the rps of every route satisfied cal-1 = (cal-2 + cal-3) ÷ 2. It added no information to a calibration with two coefficients; the measurement agreed (the mean of cal-2 and the first cal-3, 23.05%, against 23.09% for cal-1). The new cal-3 crosses the first-half and second-half split. The change was made before any prediction was produced and before any prediction scenario was measured (D155). The observation of the first cal-3 (9.68%) is kept, under the name `cal-3-orig`, as the record of a different check: that utilization is additive in the mix of routes. It is not used in the calibration, and its value is not rewritten.
- **Why cal-4.** `cal-1` to `cal-3` all have the same row count, so the weight of the number of queries and the weight of the rows read could not be told apart: in the fits that leave one run out, the coefficient of the rows moved by a factor of about 4.4 and the predicted limit rps at 10 × N_0 by a factor of about 2.1. Changing the row count moves only the rows read, so one calibration run with another row count (0.3 × N_0, even mix, R_1) was added before any prediction was made (D161). The calibration is then made on four runs, and its width on the four fits that leave one run out. The naive estimate is chosen from `cal-1` to `cal-3` only, because `cal-4` has another row count.
- **Ladder.** The total rps of a prediction scenario goes up a fixed ladder of 13 steps: 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0. The steps were fixed without reference to any model. They replaced the first design, in which a fixed rps of 2 to 4 times R_0 would have pinned every scaled scenario at 100% according to the calibration observations and made the intended rps unreachable (D155).
- **Window of a step.** A step discards its first minute and keeps a window of the smallest multiple of 40 ÷ rps seconds that is at least 300 seconds. At the low steps a fixed 5 minutes holds only a dozen or so arrivals, and the count per route can then differ from the mix by up to 100%; a window of a multiple of 40 ÷ rps seconds holds a multiple of 40 requests, so the counts per route follow the mix (D161).
- **Why t-4.** t-4 scales the traffic only, not the data. It is the condition that the naive estimate (section 3) is good at; it is there to see whether the simulator loses to it.
- **Why t-2, t-3 and t-5.** They scale the data. That is the condition that the naive estimate overlooks.

## 3. Calibration and prediction

### 3.1 Calibration

The load of a period is written (spec section 5)

```
L = Σ_r λ_r · ( c_q · count_r(N) + c_row · rows_r(N) )
    λ_r : rps of route r (taken from the scenario)
    N   : number of rows of the tables of the scenario
utilization = L / cores
```

- The two coefficients `c_q` and `c_row` are fitted to the four calibration runs `cal-1`, `cal-2`, `cal-3` and `cal-4` by non-negative least squares. Each calibration run applies a constant arrival rate for 10 minutes and the first 2 minutes are discarded. The rps of each run is checked to be within ±5% of the intended rps; a run whose rps did not come out is refused and not used.
- The estimate of `count_r` and `rows_r` is the one committed at `7c40774` (the 8 N tables grown together). It is not rebuilt after the calibration.
- The output is: `c_q`, `c_row`, the residual of each calibration scenario in utilization points, the four coefficient pairs obtained by leaving out one run at a time, and the calibration width (the range of those fits; spec section 6.3).
- Check of independence: the matrix of the rps of the 8 routes in the calibration runs has rank 3. The rank is computed from the plans, not assumed. (`cal-4` has the same mix as `cal-1`, so it adds the row count, not a new mix.)
- Check of additivity: the observation of `cal-3-orig` is compared with the value obtained by adding up from `cal-1` and `cal-2`; the difference is recorded in utilization points.
- **If a coefficient is cut to zero** by the non-negative fit, the run stops before any prediction is made and the case is reported. It would mean that the two-coefficient form does not hold in this lab. The run does not go on with a mark and a warning (the spec, section 5, allows going on with a mark; this run is stricter on purpose). The same stop applies to the fits that leave one run out.

Figures: section 7.1.

### 3.2 Prediction

The prediction is made by the simulator's own parts (`bin/simulate`: calibration and prediction); no separate calculation is written for the ladder. For every prediction scenario and every one of the 13 steps it gives

- the utilization at the point estimate, and the utilization on the safe side (the routes marked `plan_unstable` or `plan_changes_at_scale` put at the upper side of their width, spec sections 4.3 and 6.3). The point always comes from the single estimate at `7c40774`; the marks and the upper side come from the EXPLAIN estimate of the row count the scenario reads (10,000 rows for t-4, 30,000 for t-1, 100,000 for t-2, t-3 and t-5), because the marked statements differ with the row count (D163);
- the **naive estimate** (spec section 7.3):

```
naive utilization = utilization of a calibration scenario
                    × (total rps of the prediction step ÷ total rps of that calibration scenario)
```

  The three calibration scenarios `cal-1` to `cal-3` have the same total rps; the one with the largest observed utilization is used (`cal-2`, 36.4054% at 1.0 rps in total). `cal-4` is not a candidate because it has another row count (spec section 7.3). The spec states this rule for two calibration scenarios (the larger total rps, and for equal totals the larger observed utilization); here it is extended to three. The estimate ignores the growth of the data and the mix of the routes;
- the **limit rps**: the total rps of the scenario at which the database utilization reaches the threshold of 80% with 2 CPUs. For a fixed number of rows and a fixed mix the load is proportional to the rps (the formula above), so the limit is solved directly from that relation and is not searched in steps of 0.1 times. It is given at the point, on the safe side, and for the naive estimate (the naive formula solved for 80%).

The file also holds the commit of the estimate used, the calibration coefficients and the hash of the inputs.

Tests fixed with the prediction: (1) running the same parts at the solved limit rps gives exactly 80%; (2) on a made-up scenario, the solved limit agrees with the limit found by searching in 0.1 steps to within one step; (3) the utilization of a step is byte-identical to the value `bin/simulate` gives for a scenario file that states the same rps.

### 3.3 The order that makes the test valid

1. Calibrate on `cal-1`, `cal-2`, `cal-3`.
2. Produce the prediction file and commit it, with the paths named. Record the commit hash and time.
3. Only then measure the prediction scenarios.

The measuring entry has a gate: it starts only after confirming that the prediction file is committed in HEAD and that the working tree has no difference in it. If that cannot be confirmed it stops and returns a non-zero exit code. A test that fails when the gate is removed is part of the tests.

After the prediction is committed, the prediction, the estimate, the calibration, the ladder, the scenarios and the rules of section 5 are not changed. If a step is later found to be wrong, it is recorded and reported; it is not repaired in the prediction. No step of a prediction scenario was measured before the commit.

| | |
| --- | --- |
| Commit of the prediction file | `5bdf6e88254439dd6127b39a503cf046384bac91` |
| Commit time | 2026-11-10T10:18:07+09:00 |
| First step of a prediction scenario started | 2026-11-10T10:19:58+09:00 (`t-4`, 0.05 rps) |

## 4. Measurement

- **Order.** By number of rows: N_0 (t-4), then 3 × N_0 (t-1), then 10 × N_0 (t-2, t-3, t-5). Each time the number of rows changes, the 8 N tables are set to that number together. The automatic recalculation of statistics stays at its default.
- **Steps.** In each scenario the ladder is climbed from the lowest step. A step applies a constant arrival rate; the first minute is discarded; the utilization and the rps are taken over the kept window, the smallest multiple of 40 ÷ rps seconds that is at least 300 seconds (section 2).
- **Stop.** A scenario is stopped, and its later steps are not measured, after the first step whose database utilization is 80% or more, or whose intended rps did not come out (outside ±5%).
- **What is recorded for each step.**
  - the intended rps and the rps that came out (valid when within ±5%; exactly 5% is valid);
  - the database utilization;
  - the CPU utilization of the application;
  - the number of requests k6 dropped;
  - the breakdown of the HTTP statuses.
- **If the rps did not come out** and the database utilization is below 80%, the step is recorded as "stuck on the application side", with the application CPU utilization. A step whose rps did not come out is "invalid"; it is recorded and never used silently.
- **No comparison while measuring.** Prediction and measurement are put side by side only in the verdict (section 5).

## 5. Verdict

The pass lines are those of spec section 7.4 B. They are not moved by this file, and a failure does not move them.

A **valid step** is one whose rps came out within ±5%. A **usable step** is a valid step whose measured utilization is between 10% and 90%.

| Item | Lowest standard | Goal |
| --- | --- | --- |
| 1. Mean absolute difference between predicted and measured utilization is smaller than that of the naive estimate | required | required |
| 2. Mean absolute difference between predicted and measured utilization | within 15 points | within 5 points |
| 3. Share of steps on which the judgement "threshold exceeded or not" agrees with the measurement (all valid steps) | 80% or more | 80% or more |
| 4. Share of scenarios in which the predicted limit rps (point) is within ±X% of the measured limit rps | ±20% in 80% or more | ±10% in 80% or more |

- Items 1 and 2 use the usable steps; item 3 uses all valid steps; item 4 uses the scenarios whose limit rps could be measured.
- The naive estimate gives the utilization of each step and the limit rps by the same method. Item 4 is shown for the naive estimate beside the simulator's; beating the naive estimate on item 4 is not used for the verdict.
- **Measured limit rps.** Where the utilization crosses 80% between two valid steps, the two are joined by a straight line. If the steps at 80% or more were invalid, the line is extended from the two valid steps with the largest rps, and the extension is recorded. A scenario with fewer than 2 valid steps has its limit rps "not measured".
- **Undecidable.** If there are fewer than 10 usable steps, or fewer than 4 scenarios whose limit rps was measured, B is "undecidable". Undecidable is not treated as a pass.
- **Not a pass is not repaired here.** On a fail or an undecidable result nothing is changed; the items that missed are recorded and the next step is decided separately. A re-check after a repair uses new prediction scenarios (5 × N_0 and 20 × N_0) so that data already seen are not used (spec section 7.4).
- The verdict is made from the raw records alone, and the order is checked: the prediction commit is earlier than the first measured step of a prediction scenario, by the git log and the times in the records of the measurement.

## 6. What the lab cannot say

The lab reproduces the load of a busy period on one machine. It does not say how the following behave in production, and they must be stated wherever the result is quoted:

- **Cache behaviour** of a real deployment.
- **Data that does not fit in the database's memory.** The buffer pool is made large on purpose so that the databases of all scenarios fit; a deployment whose data is larger than its memory reads from disk.
- **Contention with other work** on the same database or machine (batch jobs, other applications, backups).
- **Write load.** Only GET routes are loaded and every request is rolled back, so the number of rows is constant during a measurement and the cost of writes, locks and replication is absent.

Two more properties of this lab are worth stating because they bear on what a verdict means. A request costs one statement more than in production (the entry script asks which database it is on, as the guard of safety condition 2). And the fixed 2-CPU database with an application given 6 CPUs makes the database the first limit by construction; a deployment where the application limits first is outside this check (the steps stuck on the application side are recorded for that reason).

A pass here would show that the prediction agreed with a busy period reproduced under these conditions. It would say nothing about values measured in production, and the file does not claim any.

## 7. Calibration, prediction, measurement and verdict

### 7.1 Calibration

Records: `docs/research/lab-data/calibration/` (the plans, the k6 summaries, the raw CPU samples, the record of each run, and the input and output of each fit). Every run applied 10 minutes of constant arrival rate with the first 2 minutes discarded, on a database with 2 CPUs, and its rps came out (`valid`).

| Run | Rows per scaled table | Mix | Total rps | Database utilization (%) |
| --- | --- | --- | --- | --- |
| `cal-1` | 10,000 | even | 1 | 23.0922 |
| `cal-2` | 10,000 | first four routes 80% | 1 | 36.4054 |
| `cal-3` | 10,000 | odd places 80% | 1 | 9.9319 |
| `cal-4` | 3,000 | even | 4 | 27.6575 |

The fit on the four runs (non-negative least squares, estimate at `7c40774`, not rebuilt): `c_q` = 2.81705e-4 and `c_row` = 1.95063e-6, no coefficient cut to 0.

| Run | Observed (%) | Fitted (%) | Residual (points, observed − fitted) |
| --- | --- | --- | --- |
| `cal-1` | 23.0922 | 23.0464 | +0.0457 |
| `cal-2` | 36.4054 | 36.6529 | −0.2475 |
| `cal-3` | 9.9319 | 9.2769 | +0.6550 |
| `cal-4` | 27.6575 | 27.5872 | +0.0703 |

Width of the calibration (the fits that leave one run out; none is cut to 0):

| Left out | `c_q` | `c_row` |
| --- | --- | --- |
| nothing (all four) | 2.81705e-4 | 1.95063e-6 |
| `cal-1` | 2.82968e-4 | 1.81417e-6 |
| `cal-2` | 2.74741e-4 | 2.84096e-6 |
| `cal-3` | 2.87058e-4 | 1.37851e-6 |
| `cal-4` | 1.74879e-4 | 1.12854e-5 |

`c_q` lies between 1.749e-4 and 2.871e-4 (a factor of 1.64) and `c_row` between 1.379e-6 and 1.129e-5 (a factor of 8.2), so the weight of the rows is still the less certain of the two. The fit that leaves `cal-4` out is the fit on three runs that was made first.

**The three-run fit, kept as a record.** On `cal-1` to `cal-3` alone the fit was `c_q` = 1.74879e-4 and `c_row` = 1.12854e-5 with no cut, but two of the three fits that leave one run out came out with `c_q` = 0 (the exact solution of two runs for two coefficients was negative). That is what led to the addition of `cal-4` (section 2) before any prediction was made. With `cal-4` the weight of the rows came out about 5.8 times smaller and the weight of the queries about 1.61 times larger.

**Independence.** The matrix of the rps of the 8 routes (4 runs × 8 routes) has rank 3 (singular values 1.5427, 0.2121, 0.2005, 0), because `cal-4` has the mix of `cal-1` (4 times its rps on every route). The three runs `cal-1` to `cal-3` have rank 3 (singular values 0.6373, 0.2121, 0.1177); with `cal-3-orig` in place of `cal-3` the rank is 2. What `cal-4` adds is the row count: the ratio of rows read to queries is 11.846 (`cal-1`), 11.207 (`cal-2`), 11.943 (`cal-3`) and 8.611 (`cal-4`).

**Additivity.** The observation of `cal-3-orig` is 9.6838%. The value from adding up `cal-1` and `cal-2` (`2 × cal-1 − cal-2`) is 9.7789%. The observation is 0.0951 points lower (−0.97% of the added value). The recorded values are used as they are, with no correction for the small difference in achieved rps (0.9917, 0.9979, 0.9979).

### 7.2 Prediction

The prediction was committed before any step of a prediction scenario was measured (section 3.3): commit `5bdf6e88254439dd6127b39a503cf046384bac91`, 2026-11-10T10:18:07+09:00, with two files, `poc/lab/predictions/ladder-prediction.json` (sha256 `d0fb256dd420a84fea7886f7a6e67a63e1d28c16dbed688c0cc9674722623189`) and its input `poc/lab/predictions/ladder-prediction-input.yaml` (sha256 `23f2722f127ef5b1dbdd0d2d0a75e2742fb377f43c26ed8545fda4105fe4977b`). The gate of the measuring entry answered `PREDICTION OK` for this commit.

- Calibration: the four-run fit of section 7.1; no coefficient cut, no warning.
- Point estimate: `7c40774`.
- Marks and upper side (section 3.2): t-4 from the 10,000-row estimate (`550c49e`, 3 marked routes), t-1 from the 30,000-row estimate (`124a47a`, 4 marked routes), t-2, t-3 and t-5 from the 100,000-row estimate (`7c40774`, 3 marked routes).
- Naive estimate: basis `cal-2` (10,000 rows, 1.0 rps in total, 36.4054%).
- Resources: 2 CPUs, threshold 80%, 13 steps.

Predicted limit rps (total rps at which the database utilization reaches 80%):

| Scenario | Rows | Mix | Point | Safe side | Naive |
| --- | --- | --- | --- | --- | --- |
| t-1 | 30,000 | even | 1.1157 | 1.0811 | 2.1975 |
| t-2 | 100,000 | even | 0.3056 | 0.3055 | 2.1975 |
| t-3 | 100,000 | first 4 80% | 0.1917 | 0.1917 | 2.1975 |
| t-4 | 10,000 | even | 3.4713 | 2.6318 | 2.1975 |
| t-5 | 100,000 | last 4 80% | 0.7529 | 0.7528 | 2.1975 |

The naive estimate is proportional to the rps of one calibration run, so it gives the same limit for every row count and every mix. The utilization of every step, at the point, on the safe side and naive, is in the prediction file; the step tables of section 7.4 put it beside the measurement.

### 7.3 Measurement

Records: `docs/research/lab-data/ladder/` (per step: the plan, the k6 summary, the raw CPU samples of the database and of the application, the record; per scenario: `ladder.json`; the tables of every step in the README of that directory). The measurement was made by row count: `t-4` at 10,000 rows, then `t-1` at 30,000, then `t-2`, `t-3` and `t-5` at 100,000. Each time the row count changed, the 8 N tables were set to that count together; the automatic recalculation of statistics was left at its default. Nothing was compared with the prediction while measuring.

| Scenario | Rows | Mix | Steps measured | Last step: rps, database utilization | Stopped because |
| --- | --- | --- | --- | --- | --- |
| t-4 | 10,000 | even | 13 | 5.0 rps, 96.6% | database utilization 80% or more |
| t-1 | 30,000 | even | 10 | 1.5 rps, 87.8% | database utilization 80% or more |
| t-2 | 100,000 | even | 8 | 0.7 rps, 99.0% | database utilization 80% or more |
| t-3 | 100,000 | first 4 80% | 6 | 0.3 rps, 81.2% | database utilization 80% or more |
| t-5 | 100,000 | last 4 80% | 10 | 1.5 rps, 97.1% | database utilization 80% or more |

All five scenarios stopped on the first stop rule. No step had an rps outside ±5%, so no step was recorded as stuck on the application side or as invalid. The first step started at 2026-11-10T10:19:58+09:00, after the commit of the prediction (10:18:07).

At 100,000 rows some requests failed with HTTP 500, and at the high steps some requests got no response (status 0). The application's log shows PHP stopping a request at its 30-second execution limit inside the database layer; the log keeps no request path, but the counts per step show that it is always a single route of the first four of the route list. After all the measurements, one request to each of the first four routes at 100,000 rows (one at a time, from inside the closed network, not recorded as a step) singled the route out: `GET /books/{bookSlug}/export/zip` gave no response within 45 seconds, while the other three answered (`chapter/references` and `page/references` in about 0.03 s, `GET /books/{slug}` in 9.9 s with a 48 MB body). The spec has no stop rule for it (the stop rules are the database utilization and the rps that did not come out), so it was recorded and the measurement went on. The database utilization of a step includes the database time of those requests. The score does not read the HTTP statuses (section 7.7).

One slip during the measurement is recorded for completeness: while step 2 of `t-2` was being measured, a search of the web server's log was run inside the web container (its target did not end in a link to standard output, and it stopped twice). It added no request, but a small load overlapping the window cannot be excluded. Step 2 of `t-2` came out at 0.0701 rps and 12.5%, and its place among the other steps shows no disturbance. The check was changed to read the logs from outside the lab.

### 7.4 Verdict

The score was made by the tool in `poc/lab/score/` from the committed prediction file and the records of section 7.3, with the rules of section 5; its input and output are in `docs/research/lab-data/score/` (`score.md`, `score.json`). No rule, prediction, estimate, calibration, step or scenario was changed to make it. From the commit of the prediction to the commit of the measurements, nothing changed outside the records of the measurements and the decision log.

**B is not undecidable.** There are 31 usable steps (10 are needed) and the limit rps was measured in 5 scenarios (4 are needed), in all five by joining two valid steps with a line; none was extended and none was "not measured". All 47 measured steps are valid.

**B fails: the lowest standard is not met** (item 4). The goal is not met either (items 2 and 4).

| Item | Value | Lowest standard | Goal |
| --- | --- | --- | --- |
| 1. mean abs. difference smaller than the naive estimate | 12.25 pt vs naive 23.10 pt | met | met |
| 2. mean abs. difference (lines 15 / 5 points) | 12.25 pt | met | not met |
| 3. "exceeded the threshold" agrees, all valid stages (80% or more) | 44/47 = 93.6% (naive 87.2%) | met | met |
| 4. predicted limit rps within ±20.0% / ±10.0% of the measured one (80% or more) | 2/5 and 1/5 | not met | not met |

The figures in brackets and the line below are for reference only and are not the numbers of the verdict. The verdict is made on the point prediction for each item (for item 3, the share of the prediction, 44/47; the naive 87.2% is a reference value). Item 4 for the other two predictions, for reference (not used for the verdict): safe side 1/5 and 0/5; naive estimate 0/5 and 0/5 (within the minimum and the target tolerance).

Items 1 and 3 are met at both levels, and item 2 at the lowest standard (12.25 points against the line of 15) but not at the goal (5 points). Item 4 is where it fails: the predicted limit rps (point) is within ±20% of the measured one in 2 of 5 scenarios, and within ±10% in 1 of 5, where 80% (4 of 5) is needed.

### 7.5 Per scenario

The tables are copied from the output of the score. "Point", "safe" and "naive" are the predicted utilization; "observed" is the measured one; the differences are in utilization points (predicted minus observed). A step is "usable" when it is valid and its observed utilization is between 10% and 90%. A step marked "not measured" lies above the step at which the scenario stopped.

#### t-1: 30,000 rows, even mix

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.05 | 3.6% | 3.7% | 1.8% | 4.2% | yes | no | -0.57 | -2.33 | no | no | yes |
| 0.07 | 5.0% | 5.2% | 2.5% | 5.4% | yes | no | -0.41 | -2.88 | no | no | yes |
| 0.1 | 7.2% | 7.4% | 3.6% | 7.4% | yes | no | -0.19 | -3.72 | no | no | yes |
| 0.15 | 10.8% | 11.1% | 5.5% | 10.6% | yes | yes | +0.14 | -5.16 | no | no | yes |
| 0.2 | 14.3% | 14.8% | 7.3% | 13.8% | yes | yes | +0.54 | -6.52 | no | no | yes |
| 0.3 | 21.5% | 22.2% | 10.9% | 20.3% | yes | yes | +1.23 | -9.36 | no | no | yes |
| 0.45 | 32.3% | 33.3% | 16.4% | 30.4% | yes | yes | +1.82 | -14.07 | no | no | yes |
| 0.7 | 50.2% | 51.8% | 25.5% | 48.4% | yes | yes | +1.82 | -22.89 | no | no | yes |
| 1 | 71.7% | 74.0% | 36.4% | 67.0% | yes | yes | +4.72 | -30.58 | no | no | yes |
| 1.5 | 107.6% | 111.0% | 54.6% | 87.8% | yes | yes | +19.77 | -33.18 | yes | yes | yes |
| 2.2 | 157.8% | 162.8% | 80.1% | not measured | | | | | | | |
| 3.3 | 236.6% | 244.2% | 120.1% | not measured | | | | | | | |
| 5 | 358.5% | 370.0% | 182.0% | not measured | | | | | | | |

Measured limit rps: 1.3128 (interpolated between two valid stages).
Predicted limit rps: point 1.1157, safe 1.0811, naive 2.1975. Relative error: point -15.0%, safe -17.7%, naive +67.4%.

#### t-2: 100,000 rows, even mix

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.05 | 13.1% | 13.1% | 1.8% | 9.2% | yes | no | +3.92 | -7.35 | no | no | yes |
| 0.07 | 18.3% | 18.3% | 2.5% | 12.5% | yes | yes | +5.80 | -9.97 | no | no | yes |
| 0.1 | 26.2% | 26.2% | 3.6% | 17.5% | yes | yes | +8.70 | -13.84 | no | no | yes |
| 0.15 | 39.3% | 39.3% | 5.5% | 25.8% | yes | yes | +13.45 | -20.36 | no | no | yes |
| 0.2 | 52.4% | 52.4% | 7.3% | 34.3% | yes | yes | +18.10 | -26.98 | no | no | yes |
| 0.3 | 78.5% | 78.5% | 10.9% | 51.2% | yes | yes | +27.35 | -40.27 | no | no | yes |
| 0.45 | 117.8% | 117.8% | 16.4% | 75.6% | yes | yes | +42.16 | -59.27 | yes | no | no |
| 0.7 | 183.3% | 183.3% | 25.5% | 99.0% | yes | no | +84.27 | -73.51 | yes | yes | yes |
| 1 | 261.8% | 261.8% | 36.4% | not measured | | | | | | | |
| 1.5 | 392.7% | 392.7% | 54.6% | not measured | | | | | | | |
| 2.2 | 575.9% | 576.0% | 80.1% | not measured | | | | | | | |
| 3.3 | 863.9% | 864.0% | 120.1% | not measured | | | | | | | |
| 5 | 1309.0% | 1309.1% | 182.0% | not measured | | | | | | | |

Measured limit rps: 0.4966 (interpolated between two valid stages).
Predicted limit rps: point 0.3056, safe 0.3055, naive 2.1975. Relative error: point -38.5%, safe -38.5%, naive +342.5%.

#### t-3: 100,000 rows, first four routes 80%

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.05 | 20.9% | 20.9% | 1.8% | 14.6% | yes | yes | +6.32 | -12.73 | no | no | yes |
| 0.07 | 29.2% | 29.2% | 2.5% | 19.6% | yes | yes | +9.57 | -17.09 | no | no | yes |
| 0.1 | 41.7% | 41.7% | 3.6% | 27.5% | yes | yes | +14.20 | -23.89 | no | no | yes |
| 0.15 | 62.6% | 62.6% | 5.5% | 41.1% | yes | yes | +21.50 | -35.64 | no | no | yes |
| 0.2 | 83.5% | 83.5% | 7.3% | 54.8% | yes | yes | +28.64 | -47.55 | yes | no | no |
| 0.3 | 125.2% | 125.2% | 10.9% | 81.2% | yes | yes | +44.02 | -70.26 | yes | yes | yes |
| 0.45 | 187.8% | 187.8% | 16.4% | not measured | | | | | | | |
| 0.7 | 292.1% | 292.2% | 25.5% | not measured | | | | | | | |
| 1 | 417.3% | 417.4% | 36.4% | not measured | | | | | | | |
| 1.5 | 626.0% | 626.1% | 54.6% | not measured | | | | | | | |
| 2.2 | 918.1% | 918.2% | 80.1% | not measured | | | | | | | |
| 3.3 | 1377.2% | 1377.4% | 120.1% | not measured | | | | | | | |
| 5 | 2086.7% | 2086.9% | 182.0% | not measured | | | | | | | |

Measured limit rps: 0.2955 (interpolated between two valid stages).
Predicted limit rps: point 0.1917, safe 0.1917, naive 2.1975. Relative error: point -35.1%, safe -35.1%, naive +643.6%.

#### t-4: 10,000 rows, even mix

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.05 | 1.2% | 1.5% | 1.8% | 2.0% | yes | no | -0.81 | -0.14 | no | no | yes |
| 0.07 | 1.6% | 2.1% | 2.5% | 2.4% | yes | no | -0.80 | +0.14 | no | no | yes |
| 0.1 | 2.3% | 3.0% | 3.6% | 3.1% | yes | no | -0.75 | +0.59 | no | no | yes |
| 0.15 | 3.5% | 4.6% | 5.5% | 4.1% | yes | no | -0.68 | +1.32 | no | no | yes |
| 0.2 | 4.6% | 6.1% | 7.3% | 5.2% | yes | no | -0.61 | +2.06 | no | no | yes |
| 0.3 | 6.9% | 9.1% | 10.9% | 7.3% | yes | no | -0.42 | +3.59 | no | no | yes |
| 0.45 | 10.4% | 13.7% | 16.4% | 10.6% | yes | yes | -0.24 | +5.77 | no | no | yes |
| 0.7 | 16.1% | 21.3% | 25.5% | 15.9% | yes | yes | +0.21 | +9.56 | no | no | yes |
| 1 | 23.0% | 30.4% | 36.4% | 22.4% | yes | yes | +0.64 | +13.99 | no | no | yes |
| 1.5 | 34.6% | 45.6% | 54.6% | 34.1% | yes | yes | +0.48 | +20.52 | no | no | yes |
| 2.2 | 50.7% | 66.9% | 80.1% | 50.0% | yes | yes | +0.68 | +30.07 | no | no | yes |
| 3.3 | 76.1% | 100.3% | 120.1% | 73.8% | yes | yes | +2.29 | +46.37 | no | no | yes |
| 5 | 115.2% | 152.0% | 182.0% | 96.6% | yes | no | +18.65 | +85.44 | yes | yes | yes |

Measured limit rps: 3.7646 (interpolated between two valid stages).
Predicted limit rps: point 3.4713, safe 2.6318, naive 2.1975. Relative error: point -7.8%, safe -30.1%, naive -41.6%.

#### t-5: 100,000 rows, last four routes 80%

| rps | point | safe | naive | observed | valid | usable | point − obs (pt) | naive − obs (pt) | predicted over | observed over | agree |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0.05 | 5.3% | 5.3% | 1.8% | 4.2% | yes | no | +1.09 | -2.40 | no | no | yes |
| 0.07 | 7.4% | 7.4% | 2.5% | 5.5% | yes | no | +1.89 | -3.00 | no | no | yes |
| 0.1 | 10.6% | 10.6% | 3.6% | 7.6% | yes | no | +3.07 | -3.91 | no | no | yes |
| 0.15 | 15.9% | 15.9% | 5.5% | 10.8% | yes | yes | +5.12 | -5.35 | no | no | yes |
| 0.2 | 21.3% | 21.3% | 7.3% | 14.1% | yes | yes | +7.11 | -6.86 | no | no | yes |
| 0.3 | 31.9% | 31.9% | 10.9% | 20.8% | yes | yes | +11.07 | -9.88 | no | no | yes |
| 0.45 | 47.8% | 47.8% | 16.4% | 30.9% | yes | yes | +16.89 | -14.55 | no | no | yes |
| 0.7 | 74.4% | 74.4% | 25.5% | 47.8% | yes | yes | +26.56 | -22.33 | no | no | yes |
| 1 | 106.3% | 106.3% | 36.4% | 67.6% | yes | yes | +38.61 | -31.24 | yes | no | no |
| 1.5 | 159.4% | 159.4% | 54.6% | 97.1% | yes | no | +62.32 | -42.45 | yes | yes | yes |
| 2.2 | 233.8% | 233.8% | 80.1% | not measured | | | | | | | |
| 3.3 | 350.6% | 350.7% | 120.1% | not measured | | | | | | | |
| 5 | 531.3% | 531.3% | 182.0% | not measured | | | | | | | |

Measured limit rps: 1.21 (interpolated between two valid stages).
Predicted limit rps: point 0.7529, safe 0.7528, naive 2.1975. Relative error: point -37.8%, safe -37.8%, naive +81.6%.

### 7.6 Steps on which the safe side was below the measurement

The safe side is meant to be an upper side. On these steps it was below the measurement:

| scenario | rps | safe | observed | valid | usable |
| --- | --- | --- | --- | --- | --- |
| t-1 | 0.05 | 3.7% | 4.2% | yes | no |
| t-1 | 0.07 | 5.2% | 5.4% | yes | no |
| t-4 | 0.05 | 1.5% | 2.0% | yes | no |
| t-4 | 0.07 | 2.1% | 2.4% | yes | no |
| t-4 | 0.1 | 3.0% | 3.1% | yes | no |

All five steps have an observed utilization below 10%, so none of them is a usable step; on the usable steps the safe side was never below the measurement.

### 7.7 Comparison with the naive estimate

- **Item 1** (the mean absolute difference over the usable steps): 12.25 points for the prediction and 23.10 points for the naive estimate. The prediction is closer, at both levels of the standard.
- **Item 3** (the judgement "threshold exceeded or not", all 47 valid steps): the prediction agrees on 44 (93.6%; this is the number of the verdict); the naive estimate agrees on 87.2% (for reference only). The three steps on which the prediction does not agree are `t-2` at 0.45 rps, `t-3` at 0.2 rps and `t-5` at 1.0 rps: in each the prediction was over the threshold and the measurement was not (117.8%, 83.5% and 106.3% predicted; 75.6%, 54.8% and 67.6% measured).
- **Item 4** (the limit rps; the verdict uses the point prediction only, and the safe side and the naive estimate below are for reference): within ±20% / ±10% of the measured limit, the point is in 2/5 and 1/5, the safe side in 1/5 and 0/5, and the naive estimate in 0/5 and 0/5.

The measured limit rps and the three predictions, with the relative error of each (copied from the score):

| Scenario | Measured limit rps | Point | Safe side | Naive |
| --- | --- | --- | --- | --- |
| t-1 | 1.3128 (interpolated between two valid stages) | 1.1157 (−15.0%) | 1.0811 (−17.7%) | 2.1975 (+67.4%) |
| t-2 | 0.4966 (interpolated between two valid stages) | 0.3056 (−38.5%) | 0.3055 (−38.5%) | 2.1975 (+342.5%) |
| t-3 | 0.2955 (interpolated between two valid stages) | 0.1917 (−35.1%) | 0.1917 (−35.1%) | 2.1975 (+643.6%) |
| t-4 | 3.7646 (interpolated between two valid stages) | 3.4713 (−7.8%) | 2.6318 (−30.1%) | 2.1975 (−41.6%) |
| t-5 | 1.21 (interpolated between two valid stages) | 0.7529 (−37.8%) | 0.7528 (−37.8%) | 2.1975 (+81.6%) |

What the tables show, without a cause being claimed for it:

- At 10,000 rows (`t-4`) the point is within 2.29 points of the measurement from 0.45 rps up to 3.3 rps, and its limit rps is 7.8% below the measured one. At 30,000 rows (`t-1`) the point is within 4.72 points up to 1.0 rps and its limit is 15.0% below.
- At 100,000 rows (`t-2`, `t-3`, `t-5`) the point is above the measurement on every step and the naive estimate is below it on every step. The predicted limit rps (point) is 35.1% to 38.5% below the measured one; the naive limit is 81.6% to 643.6% above it.
- The safe side of `t-4` is 30.1% below the measured limit rps, lower than the point (7.8% below), because the marks of the 10,000-row estimate put three routes at the upper side of their width; the measured utilization at 10,000 rows is close to the point, not to the safe side.
- At 100,000 rows one route, `GET /books/{bookSlug}/export/zip`, did not answer within the 45 seconds of the single request that singled it out, and requests to it were stopped by PHP at its 30-second limit during the steps (section 7.3). The score does not read the HTTP statuses; the database time of those requests is in the measured utilization. Whether this route explains part of the difference at 100,000 rows has not been examined here.

The cause of the misses of item 4 is not determined by this file. The verdict is a fail and nothing is changed here: the prediction, the estimate, the calibration, the steps, the scenarios and the rules stay as committed. What to repair is decided separately, and a re-check after a repair uses new prediction scenarios (5 × N_0 and 20 × N_0), as section 5 says.
