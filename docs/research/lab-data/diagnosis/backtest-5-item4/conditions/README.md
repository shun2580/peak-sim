# Conditions of the B measurements, side by side (90,000, 170,000, 300,000 rows and the dry run)

A reading of records that already exist. It lays the conditions of four measurements next to each other, checks the yardstick of the utilisation, and reads what the records say about the database's CPU time while a step waits at its end. Nothing here is a prediction, a measurement or a score. The prediction, the estimate, the calibration, the formula, the rules of the mark and of pass and fail, and the specification are as committed, and no earlier record or verdict is changed. The tables of the single counts (`../90k/`, `../170k/`, `../300k/`) and of the dry run (`../dryrun/`) use this file for their conditions and their yardstick.

Files:

- `check_conditions.py` — reads the records and writes the two CSV files; `python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/conditions/check_conditions.py`. It also runs 27 checks (below) and exits non-zero if one fails. It uses no lab container.
- `conditions.csv` — one row per measurement, the columns of the table below and more.
- `step_cpu_coverage.csv` — one row per recorded step (127 rows: 48 + 39 + 30 + 10): what the database CPU readings of the step cover and how much CPU after the window can be read from the records.

The first run of `s-3` at 170,000 rows (`ladder-170k/s-3-170000-INVALID-20261119/`) was stopped and is invalid as a whole; it is not read here. Every step counted below is a recorded step of the records that the verdicts use.

## 1. Conditions

| | 90,000 rows | 170,000 rows | 300,000 rows | dry run, 30,000 rows (not a test) |
|---|---|---|---|---|
| Measurement | B, 4th round | B, 5th round | B, 3rd round | rehearsal of the 5th round |
| Measured | 2026-11-17 to 2026-11-19 | 2026-11-19 to 2026-11-20 | 2026-11-16 | 2026-11-19 |
| Steps recorded (valid) | 48 (48) | 39 (39) | 30 (27) | 10 (10) |
| Tool (commit of the repository) | `9f96913` (stated in the records) | `7a4cf32` (stated in the records) | `0390da6` — **not stated in the records**; the last commit that touches `poc/lab/bin`, `poc/lab/load` or `poc/lab/observe` before the first scenario started (2026-11-16 09:01:52), found by commit time | `797074b` (stated in the dry run's record) |
| Prediction file the steps were measured against | `poc/lab/predictions/ladder-prediction-4-b90k.json` | `poc/lab/predictions/ladder-prediction-5-b170k.json` | `poc/lab/predictions/ladder-prediction-3.json` | the dry run's rehearsal prediction, not committed |
| What that prediction assumed of the app's limits | memory 4 GiB, 65,535 values per statement; no route taken out | memory 4 GiB, 65,535 values per statement; `GET /books/{slug}` taken out of every scenario (placeholder mark) and its share given to the others | **CPU time 30 s and memory 512 MiB** (the defaults; no placeholder limit in this prediction); no route taken out | memory 128 MiB (made up), 65,535 values; `GET /books/{slug}` taken out |
| Request time limit of the load tool (k6) | 600 s | 1,800 s | 600 s | 1,800 s |
| nginx `fastcgi_read_timeout` | 600 s | 1,800 s | 600 s | 1,800 s |
| PHP `max_execution_time`, `memory_limit`, php-fpm `request_terminate_timeout` | 0, 4G, 0s | 0, 4G, 0s | 0, 4G, 0s | 0, 4G, 0s |
| Wait at the end of a step for requests still running | up to 600 s (recorded) | up to 1,800 s (recorded) | **30 s, the load tool's own default; not in the records** (the tool before commit `66d341e` did not set it) | up to 1,800 s (recorded) |
| Was a wait cut by the time limit? | no (0 of 48 steps; longest wait 183 s) | no (0 of 39 steps; longest wait 172 s) | **yes, 24 of 30 steps** end with "N interrupted iterations" in the k6 output (1 to 7 per step) | no (0 of 10; wait 0) |
| Requests counted for "did the decided rps come out" | sent in the window | sent in the window | **finished when the window ended** (not in the records; the change is commit `66d341e`); 3 of 30 steps are invalid on that count | sent in the window |
| Database container | 2 CPUs, no memory limit | same | same | same |
| App container | 6 CPUs, memory 6 GiB (6442450944 bytes) | same | same | same |
| Window | smallest multiple of 40 ÷ rps seconds that is at least 300 s; the first 60 s are discarded. 320 to 1,600 s | 343 to 1,600 s | 356 to 1,600 s | 1,142 and 1,600 s |
| Routes sent in a step | 8 | 7 | 8 | 7 |
| HTTP 500 responses, all steps | 0 | 0 | **190** (`GET /books/{slug}`, every step: the page's statement carries more values than the database allows, SQLSTATE 1390) | 0 |
| Circumstance | all 5 scenarios ran to their own stop rule (database utilisation at or above 80%); one stop of about 28 hours between `s-4` and `s-5` | `s-3` is the second run; `s-3` to `s-5` were measured after a stop and a restart of the lab | **undecidable ladder** (specification 7.4.3): the load tool cut a running request after 30 s and counted finished requests; `s-5` was stopped in step 9 by the memory guard (no record for step 9) | 2 steps per scenario; one route taken out by a made-up mark |

The limits that stand the same in all four: DB 2 CPUs and no memory limit (`poc/lab/compose/lab.compose.yaml`, checked by the script), app 6 CPUs and 6 GiB, PHP and nginx limits opened as above. What differs, and has to be kept apart from any difference between the counts:

- **300,000 rows has an older tool**, with a 30 s wait, interrupted requests and a count of finished requests; 90,000 and 170,000 rows have the later tool that waits as long as the request time limit and counts sent requests. A step at 300,000 rows that shows less database work after the window than its rate of arrival would give may be showing a cut, not the count.
- **The request time limit is 600 s at 90,000 and 300,000 rows and 1,800 s at 170,000.** The longest wait after the window was far below 600 s at 90,000 rows, so the limit did not bite there either.
- **The 300,000-row prediction assumed the default 30 s CPU time and 512 MiB**, while the steps ran with those limits opened. The two later predictions assumed 4 GiB and a placeholder limit, and 170,000 rows takes one route out. When the columns of the prediction are set beside the measured ones, the routes and the limits behind the prediction are not the same at the three counts.
- **170,000 rows sends 7 routes**, because the prediction took `GET /books/{slug}` out; 90,000 and 300,000 rows send 8.

## 2. Yardstick of the utilisation

Both the predicted and the measured utilisation are a share of the database's **2 cores**, so they are on the same scale at all four measurements. Checked from the records:

- **Predicted.** Every prediction file states `resources.cores` 2.0 (the three ladder predictions and the dry run's; `check_conditions.py`, section "prediction yardstick"). The specification defines it in section 5 ("database utilisation = L ÷ cores", `cores: 2`), and the allocation record (`../allocation/README.md`) uses the same form: `utilisation = (c_query × queries + c_row × rows) ÷ cores`. The CPU seconds the prediction stands for in a window are `pred_util × 2 × window_s`.
- **Measured.** `run.json` of each step has `db_utilization` with `cpus` 2, `delta_usage_usec`, `delta_sec` (the window) and `utilization`. The two readings are the database container's own `cpu.stat` `usage_usec` (cgroup v2; `poc/lab/observe/sample-cpu`), one reading about every 5 s, kept in `rung-*/cpu.txt`; the tool takes the reading at the window's start and at its end by linear interpolation between the neighbouring readings. So `utilization = (usage at window end − usage at window start) ÷ 1e6 ÷ window_s ÷ 2`. I rebuilt this from `cpu.txt` for all 127 steps; the CPU time equals the recorded `delta_usage_usec` exactly (difference 0 µs) and the utilisation to 1.4e-14 points.
- **The yardstick is the same at all four**, and the container has 2 CPUs (`cpus: 2`, `cpuset` two cores). The app's utilisation in `run.json` (`app_cpu_utilization`) is a share of 6 cores and is not used for the judgment.
- **The window.** The measured CPU time is the CPU time inside the window only. The prediction's work for a step is the work of all the requests it sends at the decided rps. A request sent inside the window that finishes after it puts part of its database work outside the window; that part is read in section 3.

## 3. The database's CPU while a step waits at its end

**What is recorded.** The only record of the database's CPU time is `rung-*/cpu.txt` (container `cpu.stat`). There is no reading taken at the end of the wait, and no separate statistic at the window's end and at the wait's end. The sampler is started with `--seconds = duration + 60` (`poc/lab/bin/measure-ladder`), where `duration` is the window plus the 60 s that are discarded; it therefore **stops 53.8 to 59.5 s after the window end** in every step (`cpu_readings_reach_after_window_s` in `step_cpu_coverage.csv`). The step's wait can be longer than that.

**What can be got from it, for a step with a next step in the same scenario.**

1. *Read after the window* (`cpu_after_window_read_s`) = the last reading's `usage_usec` − the interpolated usage at the window end, in CPU seconds. Available for every step.
2. *Rest of the wait* = (`usage_usec` of the first reading of the **next step** in the same scenario) − (`usage_usec` of this step's last reading). Between two steps the tool reads settings and memory and does not touch the tables (only counters are read), the database is otherwise idle, and the gap is 2 to 3 s when the wait ended inside the readings; in the steps without late requests this gap holds 0.1 s of CPU (`next_step_gap_cpu_s`). So for a step whose wait ran past the readings the gap holds the rest of the wait's work. `spill_cpu_s` = (1) + (2) in those steps and = (1) in the others.
3. For the **last step of a scenario** there is no next step; the next scenario's first reading is separated from it by a table check and container work, and in two places (90,000 `s-4` to `s-5`, 170,000 `s-2` to `s-3`) by a stop of the lab (the container's counter restarted). The CPU between is an **upper bound** of the rest (`spill_cpu_upper_bound_s`, given only when the counter did not restart and the gap is under an hour); `spill_cpu_s` is NA there and `spill_missing` says why.

| | 90,000 | 170,000 | 300,000 | dry run |
|---|---|---|---|---|
| Steps | 48 | 39 | 30 | 10 |
| Steps with requests finishing after the window (`requests_finished_after_window` > 0) | 29 | 33 | not recorded (no `end_of_step` block) | 0 |
| Longest wait after the window | 183 s (`s-3`, 1.5 rps) | 172 s (`s-1`, 0.3 rps) | not recorded | 0 s |
| Steps whose wait ran past the readings (wait − reach > 0) | 6 | 24 | not known | 0 |
| `spill_cpu_s` read | 43 | 34 | 6 (the steps with no interrupted request) | 10 |
| `spill_cpu_s` NA | 5 (the last step of each scenario) | 5 (the last step of each scenario) | 24 (steps with interrupted requests) | 0 |
| `spill_cpu_s` ÷ the window's CPU time, steps with late requests | 0.027 to 0.124 (median 0.076; 24 steps) | 0.022 to 0.237 (median 0.119; 28 steps) | — | — |
| Upper bound for the NA steps | 3 of 5 | 3 of 5 | none | — |

How to read the `spill_cpu_s` values:

- It is a database CPU time in CPU seconds, from the window end onward; it includes the database's idle CPU (see below) and, for a step whose gap was used, 2 to 3 s of the tool's gap. At 90,000 and 170,000 rows the wait was never cut, so each late request ran to the end; the value is the database work of those requests that lay outside the window, plus idle.
- **The database was still busy when the readings stopped** in every step whose wait ran past them: the CPU rate over the last interval (`cpu_rate_at_last_reading_cores`) is 0.48 to 2.01 cores at 170,000 rows and 0.48 to 1.52 at 90,000 rows. In the five steps that ended 170,000-row scenarios the last rate was 1.32 to 2.01 cores, with 59 to 114 s of the wait unread; a rate near 2.0 means both cores were busy when the readings stopped.
- **The idle reference**: in the readings after the window of the steps with no late request (35 steps: those of 90,000 and 170,000 rows and of the dry run; the 300,000-row records have no count of late requests), the database's CPU is 0.018 cores (median), about 1 CPU second per 57 s. A `spill_cpu_s` of about 1 s in such a step is idle, not work that spilled. The same idle rate (about 0.9 points of the 2-core utilisation) is part of the measured utilisation of a low-load step.
- **300,000 rows.** The wait is not recorded. The load tool waited at most 30 s, inside the readings' reach, so a step with no interrupted iteration has all its work after the window in the readings (`spill_cpu_s` = read after the window; 6 steps, the first three steps of `s-3` and of `s-5`). In a step with interrupted iterations (24 steps), the requests the tool cut went on working in the database and the app after the tool had stopped waiting; that work crosses the gap and goes into the next step's own readings (next section), where it cannot be told from that step's own load. `spill_cpu_s` is NA there; `cpu_after_window_read_s` is what was read (a lower bound).

## 4. Waits cut by the time limit, and work of a step that goes on into the next step

- **90,000 and 170,000 rows: no step was cut.** The wait was 600 s and 1,800 s, the longest wait after a window was 183 s and 172 s, the k6 output of every step ends with 0 interrupted iterations, and no step has an HTTP status 0 (a request that got no response). The first reading interval of the step after each step (before the step's own load has started, `next_step_first_interval_rate_cores`) is 0.013 to 0.030 cores at 90,000 rows (43 step changes) and 0.013 to 0.021 at 170,000 rows (34): the idle rate. So no work of an earlier step was running when the next one started, and no work of an earlier step falls into a next step's window.
- **300,000 rows: yes.** 24 of 30 steps had requests interrupted by the load tool's 30 s stop (1 to 7 per step). At the start of the next step the database was already working in 18 of the 25 step changes (first interval 0.47 to 1.33 cores, clustered at about 0.48, 0.97 and 1.33 cores). In 7 of those 18 the rate fell below 0.1 cores 15 to 56 s after the next step began, before that step's window began (65 s after its start). In the other 11 it did not fall below 0.1 cores in the readings, because that step's own load kept the rate up, so the records do not show whether the work of the cut requests reached the next step's window. The columns `next_step_leftover_lasts_s` and `next_step_window_starts_s` give the numbers for each step change.
- The cut at 300,000 rows is therefore a reverse spill (an earlier step's work running at the start of a later step) that the records show at the start of the next step but cannot size.
- The dry run: no cut, no work at the next step's start.

## 5. Checks the script runs

All 27 pass (`python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/conditions/check_conditions.py`):

- For each of the four measurements: the recorded utilisation equals the one rebuilt from the readings over the window (largest difference 0 µs of CPU time, 1.4e-14 points); `db_utilization.cpus` is 2 in every step; one set of limits (PHP, nginx, k6) in every step; one app memory limit (6442450944 bytes) in every baseline.
- For each of the four: one prediction file in every step, and its `resources.cores` is 2.0.
- The compose file has `cpus: 2` and no `mem_limit` for the database service, and `cpus: 6` and a `mem_limit` for the app service.

## 6. What this does not say

- It does not size the work of a step that went past the readings for the last step of a scenario (only an upper bound for some), nor the work of the cut requests at 300,000 rows.
- The tool commit of the 300,000-row round is found by commit time, not read from its records.
- The wait of the 300,000-row steps (30 s) is the load tool's default as the tool's comments and the k6 output show it; it is not in a record of the steps.
- It does not allocate the difference between the predicted and measured utilisation; the cause is not read here.
