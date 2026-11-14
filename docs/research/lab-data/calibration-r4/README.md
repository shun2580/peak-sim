# Calibration measured again at the guarded entry, with the limits off (`docs/simulator-spec.md` 7.4.1)

All seven calibration runs, measured on 2026-11-15 (03:16 to 04:58, JST) at the lab's entry as it stands after the write guards (`../guarded-pass-20261115/`): the entry no longer wraps a
request in a transaction, and only `views` may be written. Settings as in `../calibration-limits-off/`: PHP's `max_execution_time` 0, php-fpm's `request_terminate_timeout` 0s, nginx's
`fastcgi_read_timeout` and the load tool's request timeout 600 seconds. The runs were measured with `bin/measure-calibration` only (the calibration gate, the write guards and the limits guard
passed before every run), one chain of commands, with the stop rules of `docs/decisions.md` D183 and D186 written into it before it was started. The earlier calibrations (`../calibration/`,
`../calibration-limits-off/`) are kept as they were and are not used by this one. **No prediction was made and no prediction scenario was measured.** The 50,000- and 200,000-row databases
were not touched.

Each run: 10 minutes at a constant arrival rate, the first 2 minutes discarded (480 s kept), a database with 2 CPUs, eight routes, 600 s request timeout. Each directory holds `plan.json`,
the load tool's `summary.json`, the raw CPU samples (`cpu.txt` of the database, `app-cpu.txt` of the app), `started_at.txt`, `ended_at.txt`, `record.json` (`poc/lab/load/summarize-run.php`),
`limits.txt` (`bin/check-limits --require=unlimited --request-timeout=600s`, exit 0, run just before the run), `stats-before.tsv` / `stats-after.tsv` (`mysql.innodb_table_stats`), and
`tables-before.tsv` / `tables-after.tsv` (the write guard (iii): the count and checksum of the three N tables, and the server's write counters). The statistics clock is the database
server's (UTC): 18:55 there is 03:55 the next day in JST.

## The runs (local time)

| Run | Rows in the three scaled tables | Mix | rps | Started | Ended | rps achieved | Requests in the kept window | Dropped | Database utilization (%) | App CPU (%) | In 20% to 30% |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `cal-1` | 10,000 | even | 1 (R_0) | 03:16:02 | 03:27:04 | 1.0 (valid) | 480 | 0 | 22.5539 | 10.81 | yes |
| `cal-2` | 10,000 | first 4 routes 80% | 1 | 03:27:20 | 03:38:22 | 0.9979 (valid) | 479 | 0 | 36.4231 | 17.43 | |
| `cal-3` | 10,000 | odd places 80% | 1 | 03:38:38 | 03:49:40 | 0.9979 (valid) | 479 | 0 | 9.6079 | 4.51 | |
| `cal-4` | 3,000 | even | 4 (R_1) | 03:50:10 | 04:01:14 | 3.9979 (valid) | 1,919 | 0 | 27.6452 | 13.96 | yes |
| `cal-5` | 30,000 | even | 0.3 (R_2) | 04:13:05 | 04:24:07 | 0.3 (valid) | 144 | 0 | 20.2543 | 9.46 | yes |
| `cal-6` | 30,000 | book page 80%, ZIP 0% | 1.5 (R_3) | 04:35:42 | 04:46:45 | 1.4979 (valid) | 719 | 0 | 22.4894 | 55.08 | yes |
| `cal-7` | 30,000 | book page 80%, ZIP 0% | 1.5 | 04:47:00 | 04:58:04 | 1.5 (valid) | 720 | 0 | 21.8128 | 53.72 | yes |

R_0 = 1 and R_1 = 4 are the values of the earlier calibrations; the runs of `cal-1` and `cal-4` are what show that they still give 20% to 30% (the rule of D183). Both did, so no search was made. Every
run had HTTP status 200 or, for `GET /oidc/callback` alone, 302 (the redirect a guest gets; the load tool counts it as failed, by design: 60, 23, 96, 240, 18, 24 and 24 requests in the seven
runs). There was no 503 and no other status; the load tool exited 0 every time.

### The searches (not used in the fit)

| Search | rps | Database utilization (%) | In 20% to 30% | Where |
| --- | --- | --- | --- | --- |
| R_2 of `cal-5`, first value tried | 0.45 (216 requests, 0.45 achieved, valid; 04:01:46 to 04:12:49) | 30.6278 | no (above) | `cal-5-search/rps-0.45/` |
| R_3 of `cal-6`, first value tried | 2.2 (1,047 requests, 2.1812 achieved, valid; app CPU 85.77%; 04:24:22 to 04:35:26) | 34.7922 | no (above) | `cal-6-search/rps-2.2/` |

The rules (D183, D186) were applied as written: 0.45 gave more than 30%, so 0.3 was measured and gave 20.2543%, inside the band, and was taken as `cal-5`. 2.2 gave more than 30%, so 1.5 was measured and gave
22.4894%, inside the band, and was taken as `cal-6`; `cal-7` was measured at the same rate straight after it. 1.82 was not needed. Both searches were made under the conditions of a run (10 minutes,
constant arrival rate, the first 2 minutes discarded, the limits off), and none of them is in the fit.

For comparison, the first run of `cal-6` in the earlier calibration (`../calibration-limits-off/cal-6-search/`) reached 0.398 rps of the 2.2 asked, at 7.93% of the database and 18.0% of the app, with every
book page lasting about 39 s. Here 2.2 rps was reached (2.1812) and the database was at 34.79%. What changed between the two is the entry (no transaction around a request, `../guarded-pass-20261115/`);
this is a comparison of two records, and nothing else was varied on purpose.

## The calibration gate

Passed before every call, with the plans as measured (`calibration-gate.json`, from the call that measured `cal-6` at 1.5 and `cal-7`): the smallest degree 0.919601 (the threshold is 0.5). The three gates for
`cal-6` and `cal-7` at 2.2, 1.82 and 1.5 rps were also run alone, with `--gate-only`, before anything was measured (`gates/`): the smallest degrees 0.939181, 0.930799 and 0.919601, the values written in D185.
The gate of the call for each run was passed with the plans of the set as they stood at that time (`cal-5` at 0.45 for its search, `cal-6` at 2.2 for its search).

## The limits (`limits/`)

| | before the change (03:14:22) | with the limit off (03:14:59) | restored (04:58:30) |
| --- | --- | --- | --- |
| `max_execution_time` as a request through php-fpm sees it | 30 | 0 | 30 |
| `request_terminate_timeout` (`php-fpm -tt`) | 0s | 0s | 0s |
| nginx `fastcgi_read_timeout` (`nginx -T`) | 120s | 600s | 120s |
| `zz-lab.ini` SHA-256, host / app container | `f495eab5...c261b` / `f495eab5...c261b` | `ff7487f4...cf38` / the same | `f495eab5...c261b` / `f495eab5...c261b` |
| `nginx/lab.conf` SHA-256 (host) | `604aedff...55ec5` | `fb4856ea...207fa` | `604aedff...55ec5` |
| `git diff` of `poc/lab/compose/` | 0 lines | 23 lines (2 files: 3 lines added, 1 changed) | 0 lines |
| `bin/check-limits` | `--require=none`: exit 0 | `--require=unlimited --request-timeout=600s`: exit 0 | `--require=limited`: exit 0 |
| app scale read | 10,000 | 10,000 | 10,000 |

The change was the same two files and the same lines as in the earlier calibrations: `max_execution_time=0` (with a comment line) appended to `zz-lab.ini`, and `fastcgi_read_timeout` 120s changed to 600s in
`nginx/lab.conf`. Copies of the two originals were kept outside the repository and put back byte for byte (the SHA-256 above). The limits were off from 03:14:43 to 04:58:15 (`limits/t-*.txt`). The raw
readings of `nginx -T`, `php-fpm -tt` and the request through php-fpm (`ini-probe.php`) are in `limits/before/`, `limits/off/`, `limits/restored/`; every run also kept its own (`settings*.txt`, `limits.txt`). The
database container was not recreated; the app and web containers were recreated by `use-db` at every change of scale (10,000, 3,000, 30,000 and back to 10,000), with the closed-network check passing every time.

## The write guards

Every run passed the guard (i) (`bin/check-writes`, the capture's record of statements: 8 routes, every write to `views` only) and the guard (iii) before and after (`tables-before.tsv`, `tables-after.tsv`).
In all nine measured runs (seven runs and two searches) the count and checksum of `entities`, `images` and `references` were identical before and after, and the only table whose write counters moved
was `views` (updates 3 to 155 in `cal-1`, and so on; the counters are cumulative since the server started). No run was stopped by a guard, and no request answered 503 (the entry answers 503 to every request once its latch is set).

## Statistics (`stats-before.tsv`, `stats-after.tsv`; D181)

No `ANALYZE` was run between runs on the same database. The tables are the 41 of each database.

| Database | Runs | Tables whose `last_update` changed | What changed |
| --- | --- | --- | --- |
| 10,000 rows | `cal-1`, `cal-2`, `cal-3` (in a row) | 0 of 41 | |
| 3,000 rows | `cal-4` | **1 of 41** | `views`: `last_update` 2026-11-11 06:49:53 to **2026-11-14 18:55:15** (03:55:15 local, in the middle of the run), `n_rows` 3,001 to 3,001 |
| 30,000 rows | the search at 0.45, `cal-5`, the search at 2.2, `cal-6`, `cal-7` (in a row) | 0 of 41 | |

The automatic recalculation ran once, on `views`, during `cal-4`, as foreseen in D183 (4); it was not stopped. Before the first run of each database `last_update` was 2026-11-11 05:17:04 to 05:17:06 UTC for every table
but `views` of the 3,000-row database, whose `last_update` was 2026-11-11 06:49:53 UTC (the automatic recalculation of the earlier calibration's `cal-4`).

## The calibration (`fit-7runs/`)

The same tool and the same rule as `../calibration-limits-off/fit-5runs/`: two coefficients (`c_query`, `c_row`), non-negative least squares, on the seven runs, the width being the seven fits that leave one run out
(`poc/lab/scenarios/make-calibration-scenario` with `--run-scale=cal-4:3000 --run-scale=cal-5:30000 --run-scale=cal-6:30000 --run-scale=cal-7:30000`, then `recalibrate`; `calibration.yaml` and `out/`). The estimate is the committed one with the tangent rule
(`../estimate-r3/rule/100k/estimate.json`). Nothing in the formula, the coefficients, the estimate or the rules was changed after seeing the result.

| | |
| --- | --- |
| `c_query` | 2.84407e-4 |
| `c_row` | 9.37963e-7 |
| coefficient cut to 0 | none (`cut_coefficients: false`, no warning) |
| squared residual (load) | 2.0571e-4 |

The queries-only fit (`c_query` 3.04132e-4, squared residual 0.362394) and the rows-only fit (`c_row` 1.13471e-6, squared residual 1.09559) are shown by the tool and not used.

| Run | Observed (%) | Fitted (%) | Residual (points, observed - fitted) |
| --- | --- | --- | --- |
| `cal-1` | 22.5539 | 22.7255 | -0.1715 |
| `cal-2` | 36.4231 | 36.2899 | +0.1332 |
| `cal-3` | 9.6079 | 9.1440 | +0.4639 |
| `cal-4` | 27.6452 | 27.7549 | -0.1097 |
| `cal-5` | 20.2543 | 20.3589 | -0.1047 |
| `cal-6` | 22.4894 | 22.1512 | +0.3383 |
| `cal-7` | 21.8128 | 22.1512 | -0.3383 |

The width of the calibration, the range of the fits made by leaving one run out:

| Left out | `c_query` | `c_row` | A coefficient was cut to 0 |
| --- | --- | --- | --- |
| nothing (all seven) | 2.84407e-4 | 9.37963e-7 | no |
| `cal-1` | 2.84861e-4 | 9.37906e-7 | no |
| `cal-2` | 2.83591e-4 | 9.38066e-7 | no |
| `cal-3` | 2.83983e-4 | 9.38015e-7 | no |
| `cal-4` | 2.84800e-4 | 9.37910e-7 | no |
| `cal-5` | 2.84645e-4 | 9.37935e-7 | no |
| `cal-6` | 2.84663e-4 | 9.23054e-7 | no |
| `cal-7` | 2.84150e-4 | 9.52874e-7 | no |

None of the eight fits has a coefficient cut. `c_query` runs from 2.83591e-4 to 2.84861e-4 (a factor of 1.0045) and `c_row` from 9.23054e-7 to 9.52874e-7 (a factor of 1.0323); the widest change of `c_row` is when `cal-6` or `cal-7` is left out,
which is what the two runs of the book-page mix are for. `cal-6` and `cal-7` are two runs of one definition and one rate and differ by 0.68 points of observed utilization (22.4894 and 21.8128); no threshold for that difference was made
(D186).

What this does not show:

- That the coefficients hold at a count of rows or a mix that was not measured. The calibration has runs at 3,000, 10,000 and 30,000 rows only, and the two runs of the book-page mix are both at 30,000.
- Anything about a prediction: none was made, and these runs were not compared with one.
- The coefficients are those of the lab's entry as it is now (no transaction around a request). The earlier calibrations were measured at a different entry, and a comparison of their coefficients with these is not a
  comparison of the same thing.
- The residuals are small (at most 0.46 points) with seven runs and two coefficients; a good fit of the runs it was made on says nothing about the runs it was not.
