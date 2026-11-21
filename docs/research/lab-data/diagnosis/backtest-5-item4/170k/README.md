# Step tables of B at 170,000 rows (fifth round)

Tables made from the records only. Nothing was measured, no container was touched, and nothing here is a prediction, a test or a verdict: the committed prediction, the estimate, the calibration, the formula, the marking rule, the judging rule, the records and the pass/fail of this ladder are as they were. Every number is a value in a record, or an arithmetic on such values named below; where a value is not in the records it is `NA` and the file says what is missing.

Made by `build_table.py` (`python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/170k/build_table.py`, from a checkout; paths are relative to the repository root). It writes `step_table.csv` (one row per step, 39 rows), `route_table.csv` (one row per route per step, 273 rows) and this file.

## Conditions of the measurement (what B at 170,000 rows had)

Read from `docs/research/lab-data/ladder-170k/README.md`, the `settings.json` of every step and `poc/lab/compose/lab.compose.yaml`. Differences in these conditions between counts are not mixed into a difference between counts in the tables.

| Condition | Value at 170,000 rows (B) |
|---|---|
| Request timeout of the load tool | 1800 s, given to every request (`k6_request_timeout`) |
| Web server timeout | nginx `fastcgi_read_timeout` 1800 s |
| Wait at the end of a step | the load tool waits for the requests still running for as long as the request timeout, 1800 s (its graceful stop). It is a wait, not a cut: the longest wait after a window's end in any step is 172.1 s, no request in any step lasted 1800 s, and every step's HTTP statuses are 200 or 302 only (the 302 is the route that redirects). So there was no cut at the end of a step. |
| PHP limits | `max_execution_time` 0 (off), `memory_limit` 4G, php-fpm `request_terminate_timeout` 0s, read from the php-fpm side before every step |
| Database container | `cpus: 2` (a limit), `cpuset` 0-1; no memory limit is set on it. The prediction's `cores` is 2.0 |
| App container | `cpus: 6`, memory limit 6 GiB; the memory/oom counters read 0 around every scenario except the stopped first run of s-3, which is not used (see below) |
| Window | the kept window of a step is `window_sec` (1600 s at 0.025 rps down to 343 s at 0.7 rps), after a 60 s warm-up inside the step's duration; the window length differs by step, and the requests sent in it run from 40 to 240 |
| Steps | 39 steps in 5 scenarios, all valid (`dropped 0`, rps within the tolerance); each scenario stopped by its own stop rule (DB utilisation at or above 80%) |
| What could not be decided | nothing in these 39 steps. The first run of s-3 was stopped at its step 7 by `docker compose stop` for the maintainer's convenience, is **invalid as a whole** for the verdict, and is **not read here**: only the second run, `s-3-170000/`, is. `s-3-170000-INVALID-20261119/` is not used |

The database server was restarted between the stop and the second run of s-3 (`ladder-170k/README.md`, "The stop and the second run of s-3"); s-1 and s-2 were measured before it and s-3 (second run) to s-5 after it. That is a condition that differs among the five scenarios; it is not an explanation of anything here.

## The yardstick of utilisation

- **Measured**: `db_utilization_percent` of the step's `record.json` = the growth of the database container's cgroup `usage_usec` (read from `cpu.stat` every ~5 s by the sampler, file `cpu.txt`) over the kept window, divided by (window seconds x 2 cores). The cores are the container's `cpus: 2` limit (`run.json`: `cpus` 2). It is the container's whole CPU time, so it includes the server's own background work and the CPU of any request that is running in the window, whichever step phase it was sent in.
- **Predicted**: `stages[].utilization.point` of the scenario in `poc/lab/predictions/ladder-prediction-5-b170k.json` for the step's rps = (c_q x statements per second + c_row x rows per second) / cores, with `cores` 2.0 (`resources.cores`). `pred_util_pct` is the point reading; the upper reading is in the table below. Both are therefore a share of the same 2 cores.
- `diff_pt` = `pred_util_pct` - `meas_util_pct` (percentage points; negative = the prediction is below the measurement).

## How each column was made

- `pred_work_per_req_cpu_s` = c_q x statements + c_row x rows read for one request of the route, with c_q = 0.0002844065851 and c_row = 9.379629857e-07 (`calibration.coefficients` of the prediction) and the statements and rows of the route in the estimate it names (`estimate-170k/170k/estimate.json`, sha256 `c7b13696e63df9f1b01126746d949200bf63bac049cace5c9bb4b958772f4f26`, checked equal to the prediction's `inputs.estimate_sha256`). For every scenario and step, the sum over routes of (the prediction's rps of the route x this value) / 2 reproduces the prediction's point utilisation (checked by the script at all 75 stages, see the checks below; the script does not take any number from this reproduction into a table other than the work per request).
- `pred_work_cpu_s` of a route = `sent_requests` x `pred_work_per_req_cpu_s`, with the requests actually sent inside the kept window (`per_route[].requests_sent`). The step's value is the sum over routes. It is the model's database CPU seconds for the requests that were sent; it is **not** the prediction's steady-state work for the window (`pred_util x 2 x window`), which is given beside it in the table below, and it is not the measured work.
- `after_window_requests`, `max_end_of_step_delay_s`: `end_of_step.requests_finished_after_window` and `longest_after_window_ms` / 1000 of the record. The first is the number of requests sent in the window that had not finished at the window's end; the second is the largest (finish time - window end) among them.
- `spill_work_cpu_s`: the database work that ran **after** the window's end for requests sent in the window. Not an estimate from the model: the route of each such request is not recorded, so no per-route estimate can be made. What the records hold is the DB CPU sampler (`cpu.txt`, the container's cgroup `usage_usec` about every 5 s), which keeps running 'duration + 60 s' after it started, i.e. about 55 s past the window's end. Two readings are used, both measured: (a) the usage at the sampler's last sample minus the usage at the window's end (linear between the two neighbouring samples); (b) for a step whose last request finished later than the sampler ran and that has a next step in the same scenario, the first reading of the next step's `cpu.txt` (taken before that step's load starts) minus this step's last usage, which covers the rest of the wait and the gap between the steps. The server's idle rate times the seconds covered is subtracted; the idle rate is the first sampling interval of the step (it ends before the scenario's first request; checked). Basis `a` = (a) alone, because the sampler outlived the last request by more than 1.5 sampling intervals; basis `a+b` = (a) + (b); `a only` = the last step of a scenario whose wait outlasted the sampler: no DB CPU after the sampler stop is recorded, the value is `NA` and `spill_missing` says so.
- `req_time_s` (route table) = the model's php CPU time bound for one request of the route: 9.11 x the larger of the point and the upper reading of the database CPU time the model gives for one request (the CPU-time mark's bound, `AppLimitRisk`; the same value the prediction file records for the longest route, 540.86 s for the ZIP export, reproduced by the script and checked equal). `req_time_source` is `upper_bound` for every route that has one; this column is kept as it was and is **not** a measured time. A route the model gives no cost (the OIDC callback: 0 statements, 0 rows) has no bound and is `NA`. The bound is a php **CPU** time of a request that runs alone; it is not an elapsed time under load, and the ZIP route's measured one-request time (next bullet) is about a quarter of the bound.
- **Measured one-request time per route** (added columns `req_time_measured_s`, `req_time_measured_reading`, `req_over_window_measured`, `running_area_s`, `arrivals_in_step`, `req_time_measured_lo_s`, `req_time_measured_hi_s` of `route_table.csv`; the `req_time_s` column and its `upper_bound` rows are unchanged). The summary of the load tool keeps avg/p95/max per step only, but its progress lines (`k6-output.txt`, about one line block per second) give, for each route's scenario (`r0`... = the routes of `plan.json` in order), the number of requests running. The area under that number over the whole step (the running requests x seconds, `running_area_s`, warm-up and the wait after the window included) divided by the number of requests the route started in the whole step (`arrivals_in_step`) is the mean elapsed time of one request of the route (Little's law): `req_time_measured_s`. The arrivals are the constant-arrival schedule of the plan (`start_offset_sec`, `rate` per `time_unit`, until `duration_sec`); the script checks that the arrivals of the seven routes add up to the load tool's final count of completed iterations in every step. **The load tool does not update a route's number after that route's schedule has ended** (the progress line shows a down arrow instead of a tick, and prints the last number again while the total in its header line keeps falling); in those lines the route's number is the total minus the other routes' numbers (a route shown as done or waiting, or not listed, has 0). This was needed in 33 of the 39 steps (2825 progress lines in all). **In 60 lines of 1 step(s) (s-1 0.3 rps ZIP 202.9 to 211.6 s, reading used 206.4 s) more than one route was in that state at once** (the ZIP route and two routes of short requests, which at that load were slow too; in the first such line their last numbers were 10, 4 and 3 running against 16 in the total, and in 58 of the 60 lines three routes were in that state, in 2 lines two); there the rest is shared in proportion to the routes' last numbers, and `req_time_measured_lo_s` / `req_time_measured_hi_s` give bounds that hold whatever the split (a route past its schedule can only have fewer running than its last printed number, so its upper bound is the smaller of that and the rest, and its lower bound is the rest minus the others' last numbers); in every other step the two are equal to `req_time_measured_s`. In the lines without a down arrow the route numbers add up to the total in every line (assertion). `req_time_measured_reading` is `below_tick_resolution` when the value is under 1 s: those routes' requests take milliseconds and a progress line sees one only by chance, so the value is not a measurement. A value of a few seconds or less (the short routes) rests on a handful of ticks and is a rough reading even when it is above 1 s (in s-1 at 0.3 rps and s-2 at 0.15 rps the short routes were slow, 10 to 25 s, and those are readings); the ZIP route is the one with a firm reading in every step. All routes stay in the table so that the weighted value can be summed. The ZIP route's measured time is 119.9 to 206.4 s over the 39 steps against the bound 540.86 s (a ratio of 1/4.5 to 1/2.6); the script checks that no route's mean and no one-by-one time exceeds the step's longest request (`summary.json` max, +1.5 s for the tick).
- `req_over_window` = `req_time_s` / `window_s`. `weight` = the route's share of the step's requests in the prediction's allocation (rps of the route / sum of rps; the load plan carries exactly these rps, checked). `weighted_req_over_window` (step) = sum over routes with a bound of (weight x `req_time_s`) / `window_s`, the upper-bound reading; the OIDC callback is left out (`NA`), so it is a **lower** reading of the weighted value (the left-out route's time is not negative). `weighted_req_over_window_measured` (step) = sum over all seven routes sent of (weight x `req_time_measured_s`) / `window_s`, the measured reading (the OIDC callback has a measured value, below the tick resolution like the other short routes, so it is in the sum). Its weight differs by scenario (`weight` in `route_table.csv`: 1/7 in s-1, 0.05 in s-2 and s-5, 0.2 in s-3, 1/15 in s-4).
- `queue_sign`: whether the requests waited longer later in the step, read from the ZIP route's running numbers (the only route whose requests are long enough to see). The ZIP requests are put one by one in first-in-first-out order: the k-th request to end is taken to be the k-th that arrived (arrival times from the plan; the number ended in a tick = arrivals so far - running now, the end placed in the middle of the tick), so each ZIP request has an elapsed time. The ZIP requests that arrived in the first half of the kept window are compared with those that arrived in the second half: `yes` if the second half's mean is 1.20 times the first half's or more, `no` if less, `NA` if a half has no ZIP request. **The line 1.20 is the builder's line, fixed before the numbers were read; it is not a rule of the test.** The order of ending is not always the order of arriving when requests overlap, so a single request's time is approximate; the means of a half are less so. A half with 1 or 2 requests is a weak reading (the counts are in `queue_evidence`); the minimum of requests per half was first 2 and was lowered to 1 after the first run showed 11 steps with 1 request in a half, and no step's sign changes with it (every step read at 1 request per half has a ratio between 0.99 and 1.01). `queue_evidence` gives the means, the counts, the ratio, the mean number of ZIP requests running in the two halves, the DB CPU share in the two halves and the avg/p95/max of the step.

## Reverse spill (the previous step's work running into the next step's window)

- At the end of a step the load tool waits for **every** request to finish (graceful stop 1800 s; no request lasted that long), and the next step starts only after the load tool, the two samplers and the write guard have ended. The gap between the end of one step and the start of the next, over the 34 consecutive pairs of steps, is 2 s at the least and 3 s at the most (from `ended_at.txt` and `started_at.txt`).
- The first sampling interval of every step, which ends before the scenario's first request, is the server's idle rate. Its largest value over the 39 steps is 0.0207 cores (the smallest 0.0127), against the in-window rates in the table: no step began with the database still working on the previous step's requests.
- Inside a step the warm-up (60 s before the window) sends requests whose work can run into the window; that is part of the measured utilisation by construction and cannot be separated here.

## Step table (excerpt of the columns that need a number beside them)

Utilisation in % of 2 cores; CPU in cpu-seconds. `pred (steady)` = the prediction's point utilisation x 2 cores x window; `pred (sent)` = the model's work for the requests that were sent (`pred_work_cpu_s`); `measured` = the growth of the DB cgroup CPU over the window; `tail a` = DB CPU in the seconds after the window that the sampler covers, above the idle rate (with the seconds covered); `spill` = `spill_work_cpu_s` with its basis (`a`, `a+b`, or `NA` for `a only`, where `tail a` is a partial reading because the sampler stopped before the last request finished).

| run | rps | window s | pred % (point / upper) | meas % | diff pt | pred (steady) cpu-s | pred (sent) cpu-s | measured cpu-s | sent | after window | longest after s | tail a cpu-s (covered s) | spill cpu-s (basis) | longest request s (step max) | DB share 1st / 2nd half % | weighted req / window (upper) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| s-1 | 0.025 | 1600 | 10.60 / 10.60 | 11.33 | -0.73 | 339.3 | 356.2 | 362.6 | 40 | 1 | 21.5 | 9.42 (55.8) | 9.42 (a) | 123.0 | 11.6 / 11.0 | 0.0483 |
| s-1 | 0.035 | 1142 | 14.84 / 14.85 | 14.92 | -0.08 | 339.0 | 356.2 | 340.8 | 40 | 1 | 51.6 | 23.40 (56.7) | 23.45 (a+b) | 122.7 | 15.1 / 14.8 | 0.0677 |
| s-1 | 0.05 | 800 | 21.21 / 21.21 | 20.29 | +0.92 | 339.3 | 356.2 | 324.6 | 40 | 1 | 71.9 | 26.27 (54.5) | 33.99 (a+b) | 123.1 | 19.4 / 21.2 | 0.0966 |
| s-1 | 0.07 | 571 | 29.69 / 29.69 | 27.92 | +1.77 | 339.0 | 356.2 | 318.9 | 40 | 1 | 88.1 | 27.58 (57.7) | 41.22 (a+b) | 125.3 | 25.4 / 30.4 | 0.1354 |
| s-1 | 0.1 | 400 | 42.41 / 42.42 | 42.93 | -0.52 | 339.3 | 356.2 | 343.4 | 40 | 2 | 100.7 | 43.68 (59.1) | 62.49 (a+b) | 129.8 | 41.8 / 44.1 | 0.1932 |
| s-1 | 0.15 | 533 | 63.62 / 63.63 | 61.84 | +1.78 | 678.1 | 712.5 | 659.2 | 80 | 3 | 119.6 | 63.83 (54.7) | 104.43 (a+b) | 136.4 | 59.4 / 64.3 | 0.1450 |
| s-1 | 0.2 | 400 | 84.82 / 84.84 | 76.03 | +8.79 | 678.6 | 712.5 | 608.2 | 80 | 5 | 128.2 | 81.31 (54.3) | 140.58 (a+b) | 148.8 | 69.6 / 82.5 | 0.1932 |
| s-1 | 0.3 | 400 | 127.23 / 127.26 | 96.36 | +30.87 | 1017.9 | 1009.4 | 770.9 | 120 | 18 | 172.1 | 114.14 (57.7) | NA (a only) | 274.5 | 93.0 / 99.7 | 0.1932 |
| s-2 | 0.025 | 1600 | 19.79 / 19.79 | 19.50 | +0.29 | 633.2 | 653.0 | 623.9 | 40 | 1 | 73.0 | 26.24 (56.1) | 33.55 (a+b) | 124.4 | 18.7 / 20.2 | 0.0902 |
| s-2 | 0.035 | 1142 | 27.70 / 27.71 | 26.98 | +0.73 | 632.8 | 653.0 | 616.1 | 40 | 1 | 90.0 | 27.19 (56.8) | 42.13 (a+b) | 125.9 | 25.6 / 28.4 | 0.1263 |
| s-2 | 0.05 | 800 | 39.58 / 39.58 | 40.53 | -0.95 | 633.2 | 653.0 | 648.5 | 40 | 2 | 102.5 | 39.34 (54.8) | 60.72 (a+b) | 130.1 | 39.8 / 41.2 | 0.1803 |
| s-2 | 0.07 | 571 | 55.41 / 55.42 | 54.86 | +0.55 | 632.8 | 653.0 | 626.5 | 40 | 3 | 112.7 | 58.07 (57.7) | 85.14 (a+b) | 135.4 | 52.5 / 57.2 | 0.2526 |
| s-2 | 0.1 | 400 | 79.16 / 79.17 | 71.58 | +7.58 | 633.2 | 653.0 | 572.6 | 40 | 4 | 121.9 | 76.23 (54.3) | 123.80 (a+b) | 145.9 | 65.1 / 78.0 | 0.3606 |
| s-2 | 0.15 | 533 | 118.73 / 118.75 | 96.39 | +22.35 | 1265.7 | 1246.7 | 1027.5 | 80 | 9 | 163.0 | 107.28 (54.3) | NA (a only) | 243.4 | 93.0 / 99.8 | 0.2706 |
| s-3 | 0.025 | 1600 | 4.95 / 4.95 | 6.20 | -1.25 | 158.4 | 178.2 | 198.5 | 40 | 0 | 0.0 | -0.07 (57.5) | -0.07 (a) | 122.0 | 4.5 / 7.9 | 0.0225 |
| s-3 | 0.035 | 1142 | 6.93 / 6.93 | 8.32 | -1.39 | 158.3 | 178.2 | 190.0 | 40 | 0 | 0.0 | -0.10 (59.1) | -0.10 (a) | 121.0 | 5.8 / 10.8 | 0.0316 |
| s-3 | 0.05 | 800 | 9.90 / 9.90 | 10.94 | -1.04 | 158.4 | 178.2 | 175.0 | 40 | 1 | 21.0 | 9.55 (57.9) | 9.55 (a) | 121.5 | 8.1 / 13.8 | 0.0451 |
| s-3 | 0.07 | 571 | 13.86 / 13.86 | 13.76 | +0.10 | 158.3 | 178.2 | 157.1 | 40 | 1 | 50.0 | 23.05 (56.1) | 23.13 (a+b) | 121.0 | 10.9 / 16.7 | 0.0632 |
| s-3 | 0.1 | 400 | 19.80 / 19.80 | 18.02 | +1.77 | 158.4 | 178.2 | 144.2 | 40 | 1 | 70.7 | 27.71 (57.9) | 33.20 (a+b) | 120.9 | 15.2 / 20.9 | 0.0902 |
| s-3 | 0.15 | 533 | 29.70 / 29.70 | 29.33 | +0.37 | 316.6 | 297.0 | 312.7 | 80 | 1 | 55.2 | 25.62 (58.3) | 25.69 (a+b) | 123.6 | 28.4 / 30.3 | 0.0677 |
| s-3 | 0.2 | 400 | 39.60 / 39.61 | 39.07 | +0.52 | 316.8 | 297.0 | 312.6 | 80 | 2 | 73.5 | 28.39 (58.0) | 35.15 (a+b) | 126.7 | 36.3 / 41.9 | 0.0902 |
| s-3 | 0.3 | 400 | 59.39 / 59.41 | 55.98 | +3.41 | 475.1 | 475.1 | 447.8 | 120 | 3 | 102.3 | 56.91 (58.1) | 77.09 (a+b) | 133.3 | 52.4 / 59.5 | 0.0902 |
| s-3 | 0.45 | 356 | 89.09 / 89.11 | 80.09 | +9.00 | 634.3 | 653.3 | 570.3 | 160 | 5 | 122.6 | 84.42 (56.1) | NA (a only) | 148.3 | 76.0 / 84.2 | 0.1013 |
| s-4 | 0.025 | 1600 | 14.84 / 14.85 | 14.76 | +0.08 | 475.0 | 475.0 | 472.4 | 40 | 1 | 20.8 | 9.12 (57.6) | 9.12 (a) | 122.0 | 14.5 / 15.1 | 0.0676 |
| s-4 | 0.035 | 1142 | 20.78 / 20.78 | 19.69 | +1.09 | 474.6 | 475.0 | 449.7 | 40 | 1 | 49.7 | 22.58 (59.5) | 22.58 (a) | 121.2 | 18.7 / 20.7 | 0.0947 |
| s-4 | 0.05 | 800 | 29.68 / 29.69 | 29.69 | -0.00 | 475.0 | 475.0 | 475.0 | 40 | 1 | 71.3 | 27.48 (58.0) | 33.12 (a+b) | 123.2 | 29.7 / 29.7 | 0.1352 |
| s-4 | 0.07 | 571 | 41.56 / 41.57 | 41.47 | +0.09 | 474.6 | 475.0 | 473.6 | 40 | 2 | 88.2 | 35.67 (56.4) | 49.92 (a+b) | 127.4 | 40.6 / 42.4 | 0.1895 |
| s-4 | 0.1 | 400 | 59.37 / 59.38 | 55.85 | +3.52 | 475.0 | 475.0 | 446.8 | 40 | 3 | 102.3 | 55.84 (58.2) | 75.68 (a+b) | 133.0 | 52.3 / 59.4 | 0.2705 |
| s-4 | 0.15 | 533 | 89.05 / 89.07 | 81.24 | +7.82 | 949.3 | 949.9 | 866.0 | 80 | 4 | 117.7 | 85.71 (58.8) | NA (a only) | 149.7 | 78.0 / 84.5 | 0.2030 |
| s-5 | 0.025 | 1600 | 3.71 / 3.71 | 4.41 | -0.70 | 118.8 | 118.8 | 141.2 | 40 | 0 | 0.0 | 0.28 (57.5) | 0.28 (a) | 121.4 | 4.4 / 4.4 | 0.0169 |
| s-5 | 0.035 | 1142 | 5.20 / 5.20 | 5.83 | -0.63 | 118.7 | 118.8 | 133.2 | 40 | 0 | 0.0 | 0.22 (54.4) | 0.22 (a) | 121.1 | 5.8 / 5.8 | 0.0237 |
| s-5 | 0.05 | 800 | 7.43 / 7.43 | 7.99 | -0.57 | 118.8 | 118.8 | 127.9 | 40 | 0 | 0.0 | 0.25 (57.6) | 0.25 (a) | 121.9 | 7.9 / 8.0 | 0.0338 |
| s-5 | 0.07 | 571 | 10.40 / 10.40 | 10.79 | -0.40 | 118.7 | 118.8 | 123.2 | 40 | 0 | 0.0 | 0.28 (56.1) | 0.28 (a) | 121.3 | 10.8 / 10.8 | 0.0474 |
| s-5 | 0.1 | 400 | 14.85 / 14.85 | 13.89 | +0.96 | 118.8 | 118.8 | 111.1 | 40 | 1 | 21.1 | 9.22 (57.7) | 9.22 (a) | 121.1 | 12.8 / 15.0 | 0.0677 |
| s-5 | 0.15 | 533 | 22.28 / 22.28 | 19.82 | +2.45 | 237.5 | 237.6 | 211.3 | 80 | 1 | 54.3 | 24.70 (58.4) | 24.72 (a+b) | 121.6 | 17.5 / 22.2 | 0.0508 |
| s-5 | 0.2 | 400 | 29.70 / 29.71 | 29.68 | +0.02 | 237.6 | 237.6 | 237.5 | 80 | 1 | 72.3 | 27.24 (57.8) | 33.45 (a+b) | 124.3 | 29.7 / 29.7 | 0.0677 |
| s-5 | 0.3 | 400 | 44.55 / 44.56 | 43.83 | +0.73 | 356.4 | 356.4 | 350.6 | 120 | 2 | 90.8 | 39.88 (58.0) | 54.49 (a+b) | 128.2 | 42.1 / 45.6 | 0.0677 |
| s-5 | 0.45 | 356 | 66.83 / 66.84 | 60.78 | +6.05 | 475.8 | 475.2 | 432.7 | 160 | 3 | 105.9 | 61.35 (56.2) | 88.18 (a+b) | 135.8 | 55.4 / 66.2 | 0.0760 |
| s-5 | 0.7 | 343 | 103.96 / 103.98 | 86.73 | +17.23 | 713.2 | 712.9 | 595.0 | 240 | 6 | 128.3 | 94.31 (54.2) | NA (a only) | 168.5 | 80.2 / 93.3 | 0.0789 |


## Measured one-request time of the ZIP route, and the queue sign

`zip bound` = `req_time_s` of the ZIP route (540.9 s, the model's php CPU bound, not a time under load); `zip measured` = running-time area / arrivals of the ZIP route (`req_time_measured_s`); `zip fifo` = the mean and the largest of the one-by-one times (first in, first out); `measured / window` = `zip measured` / `window_s`; `weighted req / window` = the upper-bound reading beside the measured reading (`weighted_req_over_window`, `weighted_req_over_window_measured`); `ratio` = second half / first half of the one-by-one ZIP times by arrival in the window (the counts in the evidence), `sign` = `queue_sign` with the line fixed at 1.20.

| run | rps | window s | zip arrivals | zip measured s | zip fifo mean / max s | zip bound s | bound / measured | measured / window | weighted req / window (upper / measured) | ratio | sign |
|---|---|---|---|---|---|---|---|---|---|---|---|
| s-1 | 0.025 | 1600 | 6 | 122.0 | 122.3 / 123.3 | 540.9 | 4.44 | 0.076 | 0.0483 / 0.0109 | 1.00 | no |
| s-1 | 0.035 | 1142 | 6 | 121.9 | 122.3 / 122.4 | 540.9 | 4.44 | 0.107 | 0.0677 / 0.0153 | 1.00 | no |
| s-1 | 0.05 | 800 | 6 | 122.0 | 122.5 / 123.4 | 540.9 | 4.43 | 0.152 | 0.0966 / 0.0218 | 1.00 | no |
| s-1 | 0.07 | 571 | 6 | 124.4 | 124.3 / 124.9 | 540.9 | 4.35 | 0.218 | 0.1354 / 0.0311 | 1.00 | no |
| s-1 | 0.1 | 400 | 7 | 127.7 | 128.1 / 129.4 | 540.9 | 4.24 | 0.319 | 0.1932 / 0.0456 | 0.99 | no |
| s-1 | 0.15 | 533 | 13 | 134.7 | 134.7 / 136.6 | 540.9 | 4.01 | 0.253 | 0.1450 / 0.0361 | 0.99 | no |
| s-1 | 0.2 | 400 | 13 | 143.8 | 143.7 / 148.9 | 540.9 | 3.76 | 0.359 | 0.1932 / 0.0514 | 1.00 | no |
| s-1 | 0.3 | 400 | 20 | 206.4 | 207.0 / 249.4 | 540.9 | 2.62 | 0.516 | 0.1932 / 0.0932 | 1.05 | no |
| s-2 | 0.025 | 1600 | 11 | 122.8 | 123.2 / 124.3 | 540.9 | 4.40 | 0.077 | 0.0902 / 0.0205 | 1.01 | no |
| s-2 | 0.035 | 1142 | 11 | 125.0 | 125.0 / 125.6 | 540.9 | 4.33 | 0.109 | 0.1263 / 0.0292 | 1.00 | no |
| s-2 | 0.05 | 800 | 12 | 128.8 | 129.2 / 130.4 | 540.9 | 4.20 | 0.161 | 0.1803 / 0.0429 | 1.00 | no |
| s-2 | 0.07 | 571 | 12 | 133.8 | 133.9 / 135.7 | 540.9 | 4.04 | 0.234 | 0.2526 / 0.0625 | 1.00 | no |
| s-2 | 0.1 | 400 | 12 | 141.9 | 142.1 / 146.4 | 540.9 | 3.81 | 0.355 | 0.3606 / 0.0946 | 0.99 | no |
| s-2 | 0.15 | 533 | 24 | 205.5 | 205.4 / 242.9 | 540.9 | 2.63 | 0.386 | 0.2706 / 0.1028 | 1.10 | no |
| s-3 | 0.025 | 1600 | 3 | 121.0 | 121.4 / 122.4 | 540.9 | 4.47 | 0.076 | 0.0225 / 0.0054 | 0.99 | no |
| s-3 | 0.035 | 1142 | 3 | 120.3 | 120.7 / 121.3 | 540.9 | 4.49 | 0.105 | 0.0316 / 0.0070 | 1.00 | no |
| s-3 | 0.05 | 800 | 3 | 120.4 | 120.9 / 121.4 | 540.9 | 4.49 | 0.151 | 0.0451 / 0.0100 | 1.00 | no |
| s-3 | 0.07 | 571 | 3 | 120.4 | 120.7 / 120.9 | 540.9 | 4.49 | 0.211 | 0.0632 / 0.0141 | 1.00 | no |
| s-3 | 0.1 | 400 | 3 | 119.9 | 120.4 / 120.4 | 540.9 | 4.51 | 0.300 | 0.0902 / 0.0200 | 1.00 | no |
| s-3 | 0.15 | 533 | 6 | 122.6 | 122.5 / 123.9 | 540.9 | 4.41 | 0.230 | 0.0677 / 0.0153 | 0.99 | no |
| s-3 | 0.2 | 400 | 6 | 124.9 | 125.4 / 126.4 | 540.9 | 4.33 | 0.312 | 0.0902 / 0.0208 | 0.99 | no |
| s-3 | 0.3 | 400 | 9 | 130.7 | 131.1 / 133.4 | 540.9 | 4.14 | 0.327 | 0.0902 / 0.0218 | 0.99 | no |
| s-3 | 0.45 | 356 | 13 | 142.3 | 142.3 / 148.4 | 540.9 | 3.80 | 0.400 | 0.1013 / 0.0269 | 0.99 | no |
| s-4 | 0.025 | 1600 | 8 | 120.8 | 121.1 / 122.4 | 540.9 | 4.48 | 0.075 | 0.0676 / 0.0151 | 1.00 | no |
| s-4 | 0.035 | 1142 | 8 | 120.7 | 120.7 / 121.4 | 540.9 | 4.48 | 0.106 | 0.0947 / 0.0212 | 1.00 | no |
| s-4 | 0.05 | 800 | 9 | 122.3 | 122.7 / 123.4 | 540.9 | 4.42 | 0.153 | 0.1352 / 0.0306 | 0.99 | no |
| s-4 | 0.07 | 571 | 9 | 126.3 | 126.4 / 127.6 | 540.9 | 4.28 | 0.221 | 0.1895 / 0.0442 | 0.99 | no |
| s-4 | 0.1 | 400 | 9 | 130.6 | 131.0 / 133.4 | 540.9 | 4.14 | 0.327 | 0.2705 / 0.0653 | 0.99 | no |
| s-4 | 0.15 | 533 | 18 | 144.9 | 144.8 / 149.9 | 540.9 | 3.73 | 0.272 | 0.2030 / 0.0544 | 0.99 | no |
| s-5 | 0.025 | 1600 | 2 | 121.0 | 121.3 / 121.4 | 540.9 | 4.47 | 0.076 | 0.0169 / 0.0038 | 1.00 | no |
| s-5 | 0.035 | 1142 | 2 | 120.9 | 120.9 / 120.9 | 540.9 | 4.47 | 0.106 | 0.0237 / 0.0053 | 1.00 | no |
| s-5 | 0.05 | 800 | 2 | 121.5 | 121.8 / 122.3 | 540.9 | 4.45 | 0.152 | 0.0338 / 0.0076 | 1.01 | no |
| s-5 | 0.07 | 571 | 2 | 121.0 | 120.9 / 120.9 | 540.9 | 4.47 | 0.212 | 0.0474 / 0.0106 | 1.00 | no |
| s-5 | 0.1 | 400 | 2 | 120.1 | 120.7 / 121.0 | 540.9 | 4.50 | 0.300 | 0.0677 / 0.0150 | 1.00 | no |
| s-5 | 0.15 | 533 | 4 | 121.1 | 121.1 / 121.6 | 540.9 | 4.47 | 0.227 | 0.0508 / 0.0114 | 1.00 | no |
| s-5 | 0.2 | 400 | 5 | 122.7 | 123.1 / 124.4 | 540.9 | 4.41 | 0.307 | 0.0677 / 0.0155 | 0.99 | no |
| s-5 | 0.3 | 400 | 7 | 126.6 | 126.6 / 128.4 | 540.9 | 4.27 | 0.316 | 0.0677 / 0.0158 | 0.98 | no |
| s-5 | 0.45 | 356 | 9 | 132.8 | 132.9 / 135.8 | 540.9 | 4.07 | 0.373 | 0.0760 / 0.0186 | 0.99 | no |
| s-5 | 0.7 | 343 | 14 | 155.5 | 155.5 / 168.5 | 540.9 | 3.48 | 0.453 | 0.0789 / 0.0227 | 0.99 | no |

Reading notes (arithmetic on the table, not a result and not a test):

- `pred (sent)` differs from `pred (steady)` only by the plan's whole numbers of requests per route (for example 6 requests of the ZIP route in a window where the rate gives 5.71); it is the sent counts, not a different model.
- Resolution of `spill`: in the 6 steps in which no request finished after the window, the tail above the idle rate is between -0.10 and 0.28 cpu-s; differences of that size are the noise of the idle rate, not work.
- Of the routes that were sent, the ZIP route is the only one whose model work for one request is more than 0.01 cpu-s. The step's longest request is between 120.9 and 274.5 s over the 39 steps (the ZIP route's, by the running numbers), and the ZIP route's php CPU bound is 540.86 s.

## Route table (what one request of each route is, at 170,000 rows)

The model's values for one request; the sent counts and weights per step are in `route_table.csv`.

| route | statements | rows read | database CPU for one request, point (s) | php CPU bound for one request (s) | note |
|---|---|---|---|---|---|
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 8.00 | 223.25 | 0.002485 | 0.023 |  |
| `GET /books/{bookSlug}/export/zip` | 203998.02 | 1430653.24 | 59.360280 | 540.864 |  |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 8.00 | 235.64 | 0.002496 | 0.023 |  |
| `GET /books/{slug}` | 47.68 | 2173668.74 | 2.052383 | 18.986 | taken out of the mix by the prediction's allocation (placeholder limit); never sent in this ladder |
| `GET /books/{slug}/references` | 7.00 | 218.00 | 0.002195 | 0.020 |  |
| `GET /images/edit/{id}` | 6.00 | 199.00 | 0.001893 | 0.017 |  |
| `GET /oidc/callback` | 0.00 | 0.00 | 0.000000 | NA | no cost in the model: no bound |
| `GET /shelves/{slug}` | 18.00 | 277.41 | 0.005380 | 0.050 |  |

## Checks run by the script

- estimate sha256 equals the one the prediction names: yes
- estimate is for 170,000 rows: yes
- reproduced php CPU bound of the longest route equals the prediction's own record: yes
- sum of (rps x work per request) / cores reproduces the prediction's point utilisation at all 75 stages (max difference 1.42e-14): yes
- 39 valid steps read (s-3 from the second run only): yes
- progress lines: lines with two or more routes past their schedule at once exist in 1 step(s), 60 lines (bounds are given for them; see the README): yes
- progress lines: in every line without a route past its schedule the route numbers add up to the total (assertion in the reader): yes
- progress lines: the arrivals of the seven routes add up to the load tool's final completed count in every step (assertion in the reader): yes
- progress lines: the route with the largest running-time area is the ZIP export in every step (assertion): yes
- progress lines: no route's mean time and no one-by-one time exceeds the step's longest request in summary.json (+1.5 s) (assertion): yes
- every step's HTTP status is 200 or 302 (no timeout, 5xx or status 0): yes
- no request lasted as long as the 1800 s request timeout: yes
- the load plan's rps of every route in every step equals the prediction's allocated rps (assertion; the script stops otherwise): yes
- the first sampling interval of every step ends before the scenario's first request (assertion): yes

## Files

- `build_table.py`: reads the records and the prediction and writes the three files below (no network, no container, no write outside this directory).
- `step_table.csv`: columns `count, run, step_rps, window_s, pred_util_pct, meas_util_pct, diff_pt, sent_requests, pred_work_cpu_s, after_window_requests, max_end_of_step_delay_s, spill_work_cpu_s, spill_missing, weighted_req_over_window, weighted_req_over_window_measured, queue_sign, queue_evidence`.
- `route_table.csv`: columns `count, run, step_rps, route, sent_requests, pred_work_per_req_cpu_s, pred_work_cpu_s, req_time_s, req_time_source, req_over_window, weight, req_time_measured_s, req_time_measured_reading, req_over_window_measured, running_area_s, arrivals_in_step, req_time_measured_lo_s, req_time_measured_hi_s` (the first eleven are as before; the last seven are added, `req_time_s` and its `upper_bound` rows are kept).
