# Step and route tables of the dry run (30,000 rows)

**Reference only. This is not a test and not a score.** The dry run (`../../../dryrun-286/`) was made to see that the chain of tools runs from the start to the end; its row count (30,000) had been seen before. The tables here lay its step records out in the same columns as the tables of the 90,000-, 170,000- and 300,000-row ladders, so that a reader can see what a step of a low load looks like in those columns. Nothing here changes a prediction, an estimate, a calibration, a formula, a rule or a score, and nothing here is carried into a real prediction or a verdict. The dry run's two steps per scenario (0.025 and 0.035 rps) sit far below any stop rule, so these tables say nothing about the rows where the real ladders were stopped.

Files: `build_table.py` (makes the two tables, `python3 -I docs/research/lab-data/diagnosis/backtest-5-item4/dryrun/build_table.py`, from the repository root or any place), `step_table.csv` (10 rows, one per step), `route_table.csv` (70 rows, one per route of each step).

## Conditions of this measurement

The conditions of all four measurements are laid side by side in `../conditions/conditions.csv` and `../conditions/README.md`. For the dry run:

| | |
|---|---|
| Rows | 30,000 (`entities`, `images`, `references`); database built without the application's own rebuild of a table |
| Tool | `bin/measure-ladder-chain` of the repository at `797074b` (read from `../../../dryrun-286/b/RECORD.md`) |
| Settings read before every step | PHP `max_execution_time` 0, `memory_limit` 4G, php-fpm `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 1800s, load tool request timeout 1800s |
| Wait at the end of a step | up to 1,800 s for requests still running; no step needed any wait (`requests_finished_after_window` 0 in all 10 steps); nothing was cut |
| CPU and memory limits | database container 2 CPUs, no memory limit; app container 6 CPUs and 6 GiB (`../conditions/README.md`) |
| Window | 1,600 s at 0.025 rps and 1,142 s at 0.035 rps (smallest multiple of 40/rps seconds that is at least 300 s); the first 60 s are discarded |
| Allocation | one route (`GET /books/{slug}`) taken out of every scenario by a made-up memory mark (128M), its share given to the other seven; 7 routes are sent in every step |
| Circumstance | rehearsal; 10 of 10 steps valid, `dropped 0` |

## Yardstick of the utilisation

Both utilisations are fractions of the database's 2 cores, so they are on the same scale.

- **Predicted** (`pred_util_pct`): the stage's point utilisation of the rehearsal prediction, times 100. The prediction states `resources.cores` 2.0, and its utilisation is `sum over routes of (rps x CPU time of one request) / cores`.
- **Measured** (`meas_util_pct`): the recorded `db_utilization_percent`. It is the database container's CPU time read from the container's own `cpu.stat` (`usage_usec`, one reading about every 5 s, `rung-*/cpu.txt`), taken between the window's two edges by linear interpolation, divided by the window length and by 2 (`run.json`: `db_utilization.cpus` 2). Rebuilt from `cpu.txt` for all 10 steps it equals the recorded value (checked by `../conditions/check_conditions.py`).
- `diff_pt` = `pred_util_pct` − `meas_util_pct`, in points of the 2-core utilisation.

## Columns

`step_table.csv`

| column | what it is | where it comes from |
|---|---|---|
| `count`, `run`, `step_rps`, `window_s` | rows, scenario, decided rps of the step, window length | `record.json` of each step |
| `pred_util_pct`, `meas_util_pct`, `diff_pt` | see above | the prediction's stage with the same total rps; `record.json` |
| `sent_requests` | requests sent inside the window, all routes | `record.json` `requests_sent_in_window` |
| `pred_work_cpu_s` | the prediction's database CPU time for the requests sent: sum over routes of (requests sent × CPU time of one request the prediction used). Not a measurement, not a result | route table, below |
| `after_window_requests`, `max_end_of_step_delay_s` | requests that finished after the window and the longest delay after the window end | `record.json` `end_of_step` |
| `spill_work_cpu_s` | database CPU time read from the window end to the last reading of the step (the readings reach about 55 s past the window end). Complete here, because no step waited at all, so the wait ended inside the reach of the readings. **It is the database's idle CPU, not work that spilled**: no request outlasted the window, and the value (0.96 to 1.08 s over about 56 s, 0.017 to 0.020 cores) is the idle rate the readings show in every step without late requests | `cpu.txt` |
| `spill_missing` | what is missing when `spill_work_cpu_s` is NA | NA in all rows (nothing missing) |
| `weighted_req_over_window` | request time ÷ window, weighted by the routes' shares | NA in all rows: it needs a request time for every route, and the records give none (see route table) |
| `queue_sign`, `queue_evidence` | `no` when no request was still running at the window end and k6 dropped no arrival; the evidence is written in the cell. NA otherwise | `record.json`, `summary.json` |

`route_table.csv`

| column | what it is | where it comes from |
|---|---|---|
| `sent_requests` | requests of the route sent inside the window | `record.json` `per_route` |
| `pred_work_per_req_cpu_s` | CPU time of one request the prediction used: `c_q x statements + c_row x rows`, the estimate's point values at 30,000 rows and the prediction's two coefficients (`c_q` 2.84407e-04 s, `c_row` 9.37963e-07 s) | `../../../dryrun-286/prediction/b/estimate-30000.json` (sha256 equal to the one the prediction records) and `../../../dryrun-286/prediction/b-fabricated-mark/ladder-prediction-dryrun-b30k-fabricated-128M.json` |
| `pred_work_cpu_s` | `sent_requests` × `pred_work_per_req_cpu_s` | |
| `req_time_s`, `req_time_source` | the one request time that exists in the rehearsal prediction: the php CPU time the model gives for the longest request (`allocation.longest_request`), `upper_bound`. It is given for `GET /books/{bookSlug}/export/zip` only; NA for every other route | the prediction's allocation block of the scenario |
| `req_over_window` | `req_time_s` ÷ `window_s`; NA where there is no request time | |
| `weight` | the route's share of the step's rps (target rps of the route ÷ decided rps) | `record.json` `per_route` |

Self-check made by `build_table.py` before it writes: the per-request values rebuild the prediction's point utilisation at every stage of every scenario (largest difference 8.9e-16), so `pred_work_per_req_cpu_s` is what the prediction itself used.

## The ten steps

| scenario | rps | window s | predicted % | measured % | diff pt | requests sent | predicted work (CPU s) | CPU read after the window (s) |
|---|---|---|---|---|---|---|---|---|
| s-1 | 0.025 | 1600 | 1.87 | 2.94 | -1.07 | 40 | 62.9 | 1.05 |
| s-1 | 0.035 | 1142 | 2.62 | 3.55 | -0.93 | 40 | 62.9 | 1.00 |
| s-2 | 0.025 | 1600 | 3.49 | 4.33 | -0.84 | 40 | 115.3 | 0.96 |
| s-2 | 0.035 | 1142 | 4.89 | 5.75 | -0.86 | 40 | 115.3 | 1.00 |
| s-3 | 0.025 | 1600 | 0.88 | 1.84 | -0.96 | 40 | 31.5 | 0.97 |
| s-3 | 0.035 | 1142 | 1.23 | 2.21 | -0.98 | 40 | 31.5 | 1.08 |
| s-4 | 0.025 | 1600 | 2.62 | 3.40 | -0.78 | 40 | 83.9 | 1.01 |
| s-4 | 0.035 | 1142 | 3.67 | 4.40 | -0.73 | 40 | 83.9 | 1.02 |
| s-5 | 0.025 | 1600 | 0.66 | 1.53 | -0.87 | 40 | 21.0 | 1.03 |
| s-5 | 0.035 | 1142 | 0.92 | 1.77 | -0.85 | 40 | 21.0 | 1.06 |

Facts of these rows, with the conditions they hold under (30,000 rows, two steps far below the stop rules, one route taken out):

- The predicted utilisation is below the measured one in all 10 steps, by 0.73 to 1.07 points. The predicted work (`pred_work_cpu_s`) is also below the CPU the database spent in the window (`window_cpu_s` of `../conditions/step_cpu_coverage.csv`: 94.2 s against 62.9 s in the first step, 48.99 s against 21.0 s in s-5's first step); the difference is 31.3 s in the first step and 28.0 s in s-5's first step, close to the idle CPU rate the readings show (about 0.018 cores, 29 s over a 1,600 s window). This is a reading of 10 low-load steps, not a result for the real ladders: the real ladders' steps are far above this load.
- `pred_work_cpu_s` is the same for the two steps of a scenario because the same number of requests (40) is sent in both with the same route shares; only the window length changes.
- The route table's `req_time_s` is NA for six of the seven routes sent (the eighth route is taken out): the step record and the prediction hold no request time per route. The step record's `summary.json` has the longest request's duration of the whole step (21.5 to 24.6 s in these steps); it is not attributed to a route here.

## What these tables do not say

- They do not say whether the real round will pass or fail, and none of their numbers is a reference value for the real round.
- The prediction behind them is the dry run's rehearsal prediction with a made-up memory mark. It is not committed (the dry run keeps its rehearsal predictions out of version control, `../../../dryrun-286/prediction/NOTES.md`). When it is not on disk, `build_table.py` writes NA in the columns that come from it (`pred_util_pct`, `diff_pt`, `pred_work_cpu_s`, `pred_work_per_req_cpu_s`) and says so; the other columns still come out.
- The wait at the end of a step was 0 in every step, so the part of the wait that the CPU readings cannot reach (they stop about 55 s after the window end; see `../conditions/README.md`) did not arise here.
