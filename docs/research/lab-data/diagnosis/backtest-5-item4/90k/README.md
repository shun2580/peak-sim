# Step tables of B at 90,000 rows (fourth round), from the records only

Tables made from the committed records of B at 90,000 rows (`../../../ladder-90k/`), one row per step that was run (48 steps: s-1 9, s-2 8, s-3 12, s-4 8, s-5 11), for the question of where the predicted and the measured utilisation part, and what lies outside the window. **Nothing here is a prediction, a score or a test.** The prediction (`poc/lab/predictions/ladder-prediction-4-b90k.json`, commit `e5f406f`), the estimate, the calibration, the formula, the mark's rule, the pass/fail rule, the spec and the records are as committed; nothing was measured, no container was touched, no existing file was changed. The allocation of the difference to causes is not done here.

`build_table.py` makes every file: `python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/90k/build_table.py` (it finds the repository root from its own place; it reads the records and writes the four CSV files next to itself). It stops if the per-request values it builds do not rebuild the committed point utilisation of all 75 stages (largest difference 1.8e-15), if the window utilisation it rebuilds from `cpu.txt` does not equal the recorded one in all 48 steps (largest difference 1.1e-16), or if the iteration counts it derives do not rebuild the load tool's own total in a step.

| file | what |
|---|---|
| `step_table.csv` | one row per step, the columns the cross-count table asks for |
| `route_table.csv` | one row per step and route, the time of one request as the **upper bound** (`req_time_source=upper_bound`) |
| `route_table_measured.csv` | the same rows with the time of one request as **measured** (`req_time_source=measured`, see "Request time") |
| `step_extra.csv` | what the two tables rest on: the database CPU in the window, after it, the sampler's reach, the load tool's in-flight figures |

## Conditions of the measurement (one place, so that a difference of condition is not read as a difference of count)

| item | at 90,000 rows | where |
|---|---|---|
| request time limit | k6 request timeout 600 s; nginx `fastcgi_read_timeout` 600 s; php-fpm `request_terminate_timeout` 0s; PHP `max_execution_time` 0 | `settings.json` of every step: `0`, `4G`, `0s`, `600s`, `600s` |
| wait at the end of a step | the load tool waits for requests still running, up to 600 s (`gracefulStop` 10m0s; `end_of_step.wait_for_running_requests` 600s). **No cut**: `0 interrupted iterations` in every step, load tool exit 0 in every step, the longest wait after a window 183.2 s (s-3, step 12), the longest request 316.1 s; both under 600 s | `k6-output.txt`, `k6.exit`, `summary.json` |
| CPU limit | DB container `cpus: 2` (cpuset 0-1); app container `cpus: 6`; load tool `cpus: 2` | `poc/lab/compose/lab.compose.yaml` |
| memory limit | PHP `memory_limit` 4G (default 512M lifted); app container 6 GiB (6,442,450,944 bytes); the compose file sets no memory limit for the DB container. No container was stopped for memory (`app_oom=0 app_oom_kill=0 db_oom=0 db_oom_kill=0` in every reading) | `ladder-90k/README.md`, `mem-sequence.txt` |
| cannot-judge reasons | none at this count: all 48 steps are valid (`rps_valid` true, `k6_dropped_iterations` 0, sent count met); each scenario stopped by its own stop rule (database utilisation at or above 80%). Two facts that are not of the measurement: s-5 was measured on 2026-11-19, 28 hours after s-4, with the database server and the app container started again (the data volume was the same); and s-1 to s-4 on 2026-11-17 | `ladder-90k/README.md` ("The stop between s-4 and s-5") |
| rows, calibration | 90,000 rows in every table; calibration cal-1 to cal-7 (`c_q` 2.84407e-4 s, `c_row` 9.37963e-7 s); estimate at 90,000 rows (`a2598db`); point reading | prediction file |

Conditions that differ from the other counts' B records (the wait, the time limit) are listed so that a difference they explain is not mixed into a difference of count; this file does not compare counts.

## Yardsticks (what a percentage is a percentage of)

- **Predicted utilisation** (`pred_util_pct`): the stage's `utilization.point` of the committed prediction, as a percentage of the database container's **2 CPU cores** (`resources.cores` 2.0): `sum over routes (rps_r x (c_q x statements_r + c_row x rows_r)) / 2`. The statements and rows of one request are the estimate's (`estimate-90000.json`, `queries` and `rows_examined`).
- **Measured utilisation** (`meas_util_pct`): `db_utilization_percent` of the step's `record.json`: the database container's CPU time in the window (`usage_usec` of the container's own cgroup, read every 5 s into `cpu.txt`, interpolated linearly at the two window edges) ÷ window length ÷ 2 cores (`run.json`, `db_utilization.cpus` 2). **It is the container's whole CPU time, so it includes the database's idle CPU** (0.018 core = 0.90 point of utilisation, see below); the record did not take it off and nothing here does.
- **Work in CPU seconds**: predicted work `pred_work_cpu_s` = sum of (requests sent in the window x the predicted CPU seconds of one request of that route), with the point reading; measured work in the window `window_cpu_s` (`step_extra.csv`) = `usage_usec` difference over the window ÷ 1e6. Both are database CPU seconds. `pred_work_cpu_s` differs from `pred_util x 2 x window` by at most 0.13% because the sent counts are whole numbers (for example 39.97 planned, 40 sent).
- **Time of one request**: see "Request time".

## Where the records are (the path)

`ladder-90k/s-N-90000/ladder.json` lists the steps. Per step `rung-NN-<rps>/`: `record.json` (window, sent, utilisation, `end_of_step`, per route sent), `plan.json` (shares, rates, warm-up 60 s), `summary.json` (request durations of the window), `cpu.txt` (database CPU, every 5 s), `k6-output.txt` (the load tool's own progress each second: requests in flight per route, iterations complete, interrupted). The prediction: `poc/lab/predictions/ladder-prediction-4-b90k.json` (read from `docs/research/lab-backtest-4.md` section 4.1 and the D-entries of section 4.4); the estimate: `docs/research/lab-data/estimate-90k/estimate-90000.json`; the window rule: spec section 7.4.3.

## Columns

`step_table.csv`

| column | meaning |
|---|---|
| `count`, `run`, `step_rps` | 90000; s-1 to s-5; the decided total rps of the step |
| `window_s` | the kept window, seconds (`window_sec`) |
| `pred_util_pct`, `meas_util_pct`, `diff_pt` | item 1: predicted (point), measured, and predicted minus measured, in points |
| `sent_requests` | item 2: requests sent in the window (all routes); per route in `route_table.csv` |
| `pred_work_cpu_s` | item 2: the database work of those requests as the prediction counts it |
| `after_window_requests`, `max_end_of_step_delay_s` | item 3: requests that finished after the window, the longest wait after the window end (`end_of_step`) |
| `spill_work_cpu_s`, `spill_missing` | item 3: database CPU seconds after the window end, **net of idle** (definition below); `NA` with the reason in `spill_missing` where the record does not reach |
| `weighted_req_over_window` | item 4: sum over routes of (share of the step's requests x upper bound of one request's time) ÷ window |
| `queue_sign`, `queue_evidence` | item 5: `yes`/`no` by the rule below, with the figures |
| `weighted_measured_req_over_window` | item 4, measured: the same sum with the measured mean time of one request of each route (`route_table_measured.csv`) in place of the upper bound; the same figure is in `step_extra.csv` |

`route_table.csv` / `route_table_measured.csv`: `sent_requests` in the window; `pred_work_per_req_cpu_s` (c_q x statements + c_row x rows of the point estimate); `pred_work_cpu_s` = sent x that; `req_time_s` and `req_time_source`; `req_over_window` = `req_time_s` ÷ window; `weight` = the route's share of the step's rps (it equals its share of the sent requests: largest difference 0).

## Database CPU after the window (item 3, and the "end of step" question)

- **Reading**: only `cpu.txt`. The sampler runs for window + 60 s from the load tool's start, so it stops 54 to 59 s after the window end. The wait at the end of a step can be longer (up to 183 s here).
- **Definition**: `spill_work_cpu_s` = the database CPU between the window end and the end of the tail, minus idle. This is the work of requests that were still running at the window end (sent in the window or in the 60 s warm-up). Where the sampler's reach covers the last request (`last_request_end_after_window_s` <= `sampled_tail_s`: 42 steps), the tail ends at the last sample. Where it does not, and the scenario has a later step (1 step: s-3, step 11, 64 s tail), the tail ends at the first sample of the next step's `cpu.txt` (taken before that step's load starts; the database was not restarted in between). Where it does not and there is no later step (5 steps, below), the value is `NA`.
- **Idle**: the database's CPU with nothing running, 0.01808 core, taken as the CPU per second in the sampled tail of the 19 steps in which no request finished after the window (and subtracted over the tail's length). It is 0.90 point of utilisation at every step; the record did not take it off. At the lowest steps the predicted work is small (for 0.025 rps in s-3, 2.0%), so this 0.9 point is most of the measured figure there.
- **Check on the tail's end**: for the 42 steps whose tail the sampler covers (each has a next step), the interval from the sampler's end to the next step's first sample holds 4.43 CPU-s in 104.7 s (0.042 core), i.e. only the tools' own reads between steps; so taking the tail to the next step's first sample, as for s-3 step 11, adds idle and little else.
- **The five `NA` steps** are the last step of each scenario, the ones nearest the 80% stop rule (s-1 0.45, s-2 0.3, s-3 1.5, s-4 0.3, s-5 1.0). The last request ended 69.3, 68.3, 183.2, 65.6 and 63.0 s after the window (`end_of_step`); the sampler stopped 57.5, 54.4, 59.4, 54.2 and 57.0 s after it; the scenario has no later step; after a scenario's last step the table-row counts and the write counters are read before anything else is recorded (and s-5 has no later scenario at all), so no clean interval can be closed from the records. What is in the record is a **lower bound**: the sampled part after the window, net of idle, is 84.9 (s-1), 79.0 (s-2), 104.9 (s-3), 74.0 (s-4) and 66.1 (s-5) CPU-s (`spill_lower_bound_cpu_s`). The spill of these five steps is not given; what is missing is the database CPU between the sampler's last sample and the end of the wait.

## Request time (item 4)

- **Upper bound (primary, `route_table.csv`)**: the mark's own conversion applied to one request: 9.11 x the larger of the point's and the upper side's database time of the route (`AppLimitRisk`), as `docs/research/lab-backtest-4.md` section 4.5 recomputes it: ZIP export 300.5 s, book page `GET /books/{slug}` 33.3 s, the other six routes under 0.03 s. It is an upper bound on one request's **PHP CPU time**, not a measured time, and not in the prediction file (which carries no CPU-time side at this count). The same figures give 10.0 times and 1.1 times the default 30 s limit, as section 4.5 says.
- **Measured (`route_table_measured.csv`)**: the mean time a request of the route spent in the system in the step, by Little's law from the load tool's own progress lines: the area under the route's in-flight series (active VUs, once a second) ÷ the route's iteration count, `ceil(rate x (duration - start offset) / time unit)` from `plan.json` (it rebuilds the load tool's total "complete" in 48 of 48 steps). It includes the requests of the 60 s warm-up and, being a 1 s snapshot, is not reliable for routes that take less than a few seconds. **The in-flight counts after a route's schedule is over are rebuilt** (see "Rebuilt in-flight counts" below); without that the times of the long routes come out too long. Each reading counts for the time to the next one (the readings are 0.2 to 1.1 s apart). In the steps up to 0.1 rps a ZIP export takes 63.5 to 66.7 s and the book page 8.4 to 10.0 s (15.2 s in the first step of s-5, after the database was started again): about a fifth and a quarter of the upper bounds. The longest ZIP mean is 138.1 s (s-3 1.5, step 12). `summary.json` also has the longest request of the window (all routes together): 63.9 to 67.7 s in those steps.
- `weighted_req_over_window` (upper bound) is 0.010 to 0.167 over the 48 steps; the ZIP export's `req_over_window` is 0.188 (window 1,600 s) to 0.939 (window 320 s). The measured weighted figure (`weighted_measured_req_over_window` in `step_table.csv` and `step_extra.csv`) is 0.0023 to 0.050. These are two different quantities; this file does not choose between them.

### Rebuilt in-flight counts

The load tool stops updating a route's own count once that route's schedule is over (its line then carries a down arrow and the count stays at its last value while the requests drain), but the total of the line keeps falling. Read as printed, the area of the long route (the ZIP export) after the schedule's end is too large: in s-1 0.2 the ZIP mean came out 70.8 s against a longest request of 69.0 s. So in every such reading the count of the stopping route is the total less the counts of the routes still updated (`parse_k6`, `split_stopping` in `build_table.py`). When more than one route is stopping at once (150 readings), only their joint count is known; it is given to the routes in the order of their typical time (the largest in-flight per started request before the stop first), each capped by the count it showed, and the caps only fall (nothing starts after the schedule is over). This order is an assumption; the joint count is exact. 1,347 readings in 29 steps were rebuilt.

Check in the script: the mean time of no route may exceed the longest request of the step (`summary.json` `duration_ms.max`). Result: 3 of 384 route rows are above it, all of the ZIP export in s-5 (0.035, 0.07 and 0.15 rps, 2 to 4 requests each), by 0.06 to 0.12 s; none is above it by more than one reading interval (1 s). The mean includes the warm-up's requests and is a 1 s snapshot, while the longest request covers the window's requests only, so a difference of this size is inside what the records can tell. The queue sign (item 5) and the in-flight means by thirds use readings inside the window, before any route is stopping, and did not change.

## Signs of waiting inside a step (item 5)

- **Source**: the load tool's per-second in-flight count (all routes), `k6-output.txt`. No per-request time series is in the record; `summary.json` has the window's average, 95th percentile and maximum only.
- **Rule** (fixed by this file, not by the spec): `yes` if the mean in-flight count over the last third of the window minus its mean over the middle third is at least 1.0 **and** the last third's mean is at least 1.15 times the middle third's. Otherwise `no`. The two thresholds are this file's choice and are the only reason for the sign; the figures are in `queue_evidence` and `step_extra.csv` so another rule can be tried.
- **Result**: `yes` in 2 of 48 steps: s-1 0.45 (in flight 5.28, 6.69, 7.97 by thirds; the rise of 1.29 and the ratio 1.19 are close to the two thresholds) and s-3 1.5 (8.26, 20.6, 26.4; the ZIP export's in flight rose from 1 to 10 through the step, stood at 10 from about 240 s to 459 s, and was 0 at 563 s when the load tool ended). The other steps are `no`, among them the other three last steps (s-2 0.3, s-4 0.3, s-5 1.0), where the mean request time was 1.33, 1.29 and 1.32 times its reference (s-1 0.45: 1.62; s-3 1.5: 4.11) but the in-flight count did not keep rising (middle to last third: 6.14 to 6.28, 5.41 to 5.37, 6.42 to 6.65). `mean_req_time_over_reference` (`step_extra.csv`) = the step's mean request time ÷ the median over the scenario's steps in which nothing finished after the window.

## Facts read from the tables (arithmetic on the records; no allocation)

1. In the 38 steps whose measured utilisation is under 60%, predicted minus measured is -1.76 to +0.46 points; in the 20 steps under 15% it is negative every time (the idle CPU of 0.90 point is inside the measured figure).
2. In the five last steps (measured 84.2, 91.8, 98.1, 90.0 and 85.7%) it is +7.28, +5.77, +24.02, +5.14 and +3.90 points: predicted above measured. These are the five steps whose spill is `NA`.
3. Database CPU after the window, net of idle, rises with load in every scenario: from 0 where nothing ran past the window to 61.6 CPU-s (s-3 step 11, 12.2% of that window's CPU); in the 23 steps with at least 5 CPU-s it is 2.5% to 12.2% of the window's CPU.
4. In those 23 steps the window's own CPU is already 0.97 to 1.04 of the predicted work, and window plus spill is 1.04 to 1.12 of it. **Consistent with, not shown**: the window also holds the tail of the 60 s warm-up's requests, so what leaves the window at its end is partly met by what enters at its start. The record does not separate the two by request.
5. Waiting is cut nowhere: no step has an interrupted iteration or a request over 600 s. **Carry-over into the next step through a cut wait does not occur in these records** (the load tool waited for every request; between a step's sampler end and the next step's first sample, taken before its load, the database used 0.042 core). The carry-over that remains is inside a step: the warm-up's tail entering the window.

## What is not in the record

- The database CPU after the sampler's last sample for the five last steps (`NA`).
- Per-request times and per-request database CPU time at 90,000 rows: the record has the route's in-flight counts and the window's duration summary, not a time per request; `per-request/` has 10,000, 30,000 and 100,000 rows only.
- Which requests of the warm-up were still running when the window began.
