# Calibration measured again with the limits off (`docs/simulator-spec.md` 7.4.1)

The calibration of the lab backtest made again under the settings of B: PHP's `max_execution_time` 0, php-fpm's `request_terminate_timeout` 0, the web server's
`fastcgi_read_timeout` and the load tool's request timeout 600 seconds. Every database had `ANALYZE TABLE` run on it before (`../analyze-lab/`). The first calibration
(`../calibration/`) is kept as it was and is not used by this one. The rule that fixed the rps of each run was committed before the first run
(`docs/decisions.md` D183 and the last section of `../calibration/README.md`). **No prediction was made and no prediction scenario was measured.** The 50,000- and
200,000-row databases were not touched.

Each run: 10 minutes at a constant arrival rate, the first 2 minutes discarded (480 s kept), a database with 2 CPUs, eight routes, 600 s request timeout. Each directory holds
`plan.json`, the load tool's `summary.json`, the raw CPU samples (`cpu.txt` of the database, `app-cpu.txt` of the app), `started_at.txt`, `ended_at.txt`, `record.json`
(made by `poc/lab/load/summarize-run.php`), `limits.txt` (the output of `bin/check-limits --require=unlimited --request-timeout=600s` run just before the run, exit 0) and
`stats-before.tsv` / `stats-after.tsv` (`mysql.innodb_table_stats` of the database: table, `last_update`, `n_rows`, `clustered_index_size`, `sum_of_other_index_sizes`).
The statistics clock is the database server's (UTC): 05:17 there is 14:17 local time.

## The runs (local time, 2026-11-11)

| Run | Rows in the three scaled tables | Mix | rps | Started | Ended | rps achieved | Requests in the kept window | Dropped | Database utilization (%) | App CPU (%) | In 20% to 30% |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `cal-1` | 10,000 | even | 1 (R_0) | 15:10:44 | 15:21:47 | 0.9958 (valid) | 478 | 0 | 23.5161 | 11.26 | yes |
| `cal-2` | 10,000 | first 4 routes 80% | 1 | 15:22:10 | 15:33:13 | 1.0 (valid) | 480 | 0 | 37.1458 | 17.77 | |
| `cal-3` | 10,000 | odd places 80% | 1 | 15:33:14 | 15:44:16 | 0.9979 (valid) | 479 | 0 | 9.9277 | 4.64 | |
| `cal-4` | 3,000 | even | 4 (R_1) | 15:44:32 | 15:55:35 | 3.9979 (valid) | 1,919 | 0 | 29.3507 | 14.94 | yes |
| `cal-5` | 30,000 | even | 0.3 (R_2) | 16:07:17 | 16:18:20 | 0.3 (valid) | 144 | 0 | 21.3341 | 10.08 | yes |

R_0 = 1 and R_1 = 4 are the values of the first calibration; the runs of `cal-1` and `cal-4` are what show that they still give 20% to 30% (the rule of D183). Both did, so
no search was repeated. The answers 302 of `GET /oidc/callback` are counted as failed by the tool, by design (one route in eight; 59, 24, 96, 239 and 18 requests in these runs).

### R_2 of `cal-5`

Doubling from 1 rps only goes up, and at 30,000 rows 1 rps is about 66% by a desk estimate from the one-request baseline, so R_2 was searched down the spec's rps steps from
0.45 (D183). The search runs have the conditions of the run itself, so the one inside the band is `cal-5` and was not made again.

| rps | Run | Database utilization (%) | In 20% to 30% |
| --- | --- | --- | --- |
| 0.45 | `cal-5/r2-search/rps-0.45` (15:56:03 to 16:07:06; 216 requests; valid) | 31.9769 | no (above) |
| 0.3 | `cal-5` | 21.3341 | yes |

## The limits (`limits/`)

| | before the change (15:10:08) | with the limit off (15:10:29) | restored (16:18:50) |
| --- | --- | --- | --- |
| `max_execution_time` as a request through php-fpm sees it | 30 | 0 | 30 |
| `request_terminate_timeout` (`php-fpm -tt`) | 0s | 0s | 0s |
| nginx `fastcgi_read_timeout` (`nginx -T`) | 120s | 600s | 120s |
| `zz-lab.ini` SHA-256 | `f495eab5...c261b` | `729f7b4f...222ed` (host = container) | `f495eab5...c261b` (equal to before; equal in the container) |
| `nginx/lab.conf` SHA-256 | `604aedff...55ec5` | `fb4856ea...207fa` | `604aedff...55ec5` (equal to before) |
| `git diff` of `poc/lab/compose/` | empty | 2 files, 3 lines added and 1 changed | empty (0 lines) |
| `bin/check-limits` | `--require=none`: exit 0 | `--require=unlimited --request-timeout=600s`: exit 0 | `--require=limited`: exit 0 |
| app scale read | 10,000 | 10,000 | 10,000 |

The change was the same two files as before: `max_execution_time=0` (with a comment line) appended to `zz-lab.ini`, and `fastcgi_read_timeout` 120s to 600s in `nginx/lab.conf`. The limits were
off from 15:10:14 to 16:18:34 (the copies of the originals were kept outside the repository). The database container was not recreated; the app and web containers were recreated by `use-db` at each change of
scale (10,000 to 3,000 to 30,000 and back to 10,000), with the closed-network check passing every time. `limits/*-settings.json` are the readings of the three limits (the `zz-lab.ini` SHA-256 of the
limits-off state differs from the one in an earlier rehearsal; the cause was not looked into, the wording of the comment line being the likely one; the restored file is byte-equal to the original).

## Statistics (`stats-before.tsv`, `stats-after.tsv`; D181)

No `ANALYZE` was run between runs on the same database. The tables are the 41 of each database; `last_update` before the first run of each database was 05:17:04 to 05:17:06 UTC.

| Database | Runs | Tables whose `last_update` changed | What changed |
| --- | --- | --- | --- |
| 10,000 rows | `cal-1`, `cal-2`, `cal-3` (in a row) | 0 of 41 across the three runs | |
| 3,000 rows | `cal-4` | **1 of 41** | `views`: `last_update` 05:17:04 to **06:49:53** (15:49:53 local, in the middle of the run), `n_rows` 3,000 to 3,001 |
| 30,000 rows | the search run at 0.45 and `cal-5` (in a row) | 0 of 41 | |

The automatic recalculation of the statistics (left at its default) ran once, on `views`, during `cal-4`, as foreseen in D183. It was not stopped. The other 40 tables of the database kept their statistics.

## The calibration (`fit-5runs/`)

The same tool and the same rule as `../calibration/fit-4runs/`: two coefficients (`c_query`, `c_row`), non-negative least squares, now on the five runs, the width being the five fits that leave one run
out (`poc/lab/scenarios/make-calibration-scenario` with `--run-scale=cal-4:3000 --run-scale=cal-5:30000`, then `recalibrate`). The estimate is the committed one that the new capture made on analyzed
databases, with the tangent rule (`../estimate-r3/rule/100k/estimate.json`, SHA-256 `d18fecb1...`); the formulas of an estimate give the counts of queries and rows at any count of rows. For reference the same fit
was also made with the estimate that the first calibration used (`../estimate/estimate.json`, `fit-5runs-old-estimate/`); the two gave the same coefficients to three digits. Nothing in the formula, the
coefficients, the estimate or the rules was changed after seeing the result.

| | |
| --- | --- |
| `c_query` | 3.12213e-4 |
| `c_row` | **0** (negative in the plain least squares and pinned at 0; `cut_coefficients: true`, with the tool's warning) |
| squared residual (load) | 3.1519e-4 |

The queries-only fit is therefore the same fit (squared residual 3.1519e-4). The rows-only fit is worse (`c_row` 1.81357e-5, squared residual 4.9925e-4), and is shown by the tool, not used.

| Run | Observed (%) | Fitted (%) | Residual (points, observed - fitted) |
| --- | --- | --- | --- |
| `cal-1` | 23.5161 | 23.6051 | -0.0890 |
| `cal-2` | 37.1458 | 37.6955 | -0.5497 |
| `cal-3` | 9.9277 | 9.4959 | +0.4318 |
| `cal-4` | 29.3507 | 28.8505 | +0.5003 |
| `cal-5` | 21.3341 | 21.1312 | +0.2030 |

The width of the calibration, the range of the fits made by leaving one run out:

| Left out | `c_query` | `c_row` | A coefficient was cut to 0 |
| --- | --- | --- | --- |
| nothing (all five) | 3.12213e-4 | 0 | yes (`c_row`) |
| `cal-1` | 3.12448e-4 | 0 | yes (`c_row`) |
| `cal-2` | 3.15572e-4 | 0 | yes (`c_row`) |
| `cal-3` | 3.11820e-4 | 0 | yes (`c_row`) |
| `cal-4` | 0 | 1.79621e-5 | yes (`c_query`) |
| `cal-5` | 3.11751e-4 | 0 | yes (`c_row`) |

Every one of the six fits has a coefficient cut to 0. In five of them it is `c_row`; leaving `cal-4` out gives the opposite fit (`c_query` cut, `c_row` 1.79621e-5), so the width runs from "queries only" to "rows only". The
`c_query` of the five queries-only fits lies between 3.1175e-4 and 3.1557e-4 (a factor of 1.012). For reference, with the estimate of the first calibration the fit without `cal-4` is not cut (`c_query` 2.98954e-4,
`c_row` 9.039e-7) and the other five are as above.

What this means for the next step, as a fact of the tools: `bin/predict-ladder` makes no prediction when a calibration coefficient was cut to 0 (`poc/lab/README.md`), so this calibration cannot be used as it is by
the ladder prediction.

For comparison, the first calibration on four runs (`../calibration/fit-4runs/`, measured with the limit on and on databases whose statistics had not been brought up to date) had `c_query` 2.81705e-4 and `c_row` 1.95063e-6, nothing cut.
