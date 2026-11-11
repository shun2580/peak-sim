# Re-check at 70,000 rows, and the baseline rebuilt on analyzed databases (2026-11-11)

One request at a time, no load, eight routes, three cold requests each (`poc/lab/diagnosis/request_probe.py run --part=both --reps=3`, the same tool and the
same items as `../diagnosis/per-request/`). Every run used `--require-limits=unlimited`: the run does not start unless PHP's `max_execution_time` is 0,
`request_terminate_timeout` is 0 and nginx's `fastcgi_read_timeout` is at least the request timeout (600 s). The lab's databases had `ANALYZE TABLE` run on
them before (`../analyze-lab/`). Nothing else used the lab during these runs: `other_requests_seen` is 0 in all 72 cold requests (3 counts x 8 routes x 3).
The 50,000- and 200,000-row databases were not touched.

| directory | rows | what it is |
| --- | --- | --- |
| `baseline-10000/`, `baseline-30000/` | 10,000, 30,000 | the baseline of `../diagnosis/per-request/` made again on analyzed databases (not a test of the estimate: these two counts are inside the capture's fit) |
| `recheck-70000/` | 70,000 | the re-check; 70,000 is not a capture count |
| `settings-stage1.md` | | first read of the lab's limits on the real lab, and the removal of the limits |

Each directory holds `limits.txt` (the output of `check-limits --require=unlimited --request-timeout=600s` before the run), `stats-before.tsv` and `stats-after.tsv`
(`mysql.innodb_table_stats` of the database: table, `last_update`, `n_rows`, `clustered_index_size`, `sum_of_other_index_sizes`), `use-db.txt` (the closed-network
check), `probe.log` and `probe/` (`http.jsonl`, `inproc.jsonl`, `context.json`, `table-counts.json`, `table.json`, and `table.md` where an estimate was given).
The statistics clock is the database server's (UTC): 05:17 there is 14:17 local time.

## Statistics state

Automatic recalculation was left at its default. Every table's `last_update` is the same before and after each run (0 tables changed in all three databases; 41
tables each), so no recalculation ran during any of the three measurements.

| rows | `last_update` before the run (UTC, earliest to latest) | after the run | tables with a changed `last_update` |
| --- | --- | --- | --- |
| 10,000 | 05:17:04 to 05:17:05 | 05:17:04 to 05:17:05 | 0 of 41 |
| 30,000 | 05:17:05 to 05:17:06 | 05:17:05 to 05:17:06 | 0 of 41 |
| 70,000 | 05:17:07 to 05:17:07 | 05:17:07 to 05:17:07 | 0 of 41 |

## Measured, one cold request (median of 3)

Statements and rows are the in-process cold request's (`measure-one`); DB CPU time is the database container's CPU time net of the empty window and idle drift.
"below floor" means the reading is no larger than three times the spread of the empty windows, so no CPU ratio is made on it.

| route | rows | statements | rows examined | DB CPU s | php CPU s | php CPU / DB CPU |
| --- | --- | --- | --- | --- | --- | --- |
| `GET /books/{slug}` | 10,000 | 45 | 126,464 | 0.156 | 0.91 | 5.85 |
| `GET /books/{slug}` | 30,000 | 45 | 378,463 | 0.324 | 2.60 | 8.03 |
| `GET /books/{slug}` | 70,000 | 45 | 882,454 | 0.784 | 6.18 | 7.89 |
| `GET /books/{bookSlug}/export/zip` | 10,000 | 12,004 | 83,696 | 3.47 | 4.28 | 1.23 |
| `GET /books/{bookSlug}/export/zip` | 30,000 | 36,004 | 250,696 | 10.22 | 12.82 | 1.25 |
| `GET /books/{bookSlug}/export/zip` | 70,000 | 84,004 | 584,696 | 24.08 | 29.98 | 1.25 |
| `GET /shelves/{slug}` | 10,000 / 30,000 / 70,000 | 18 / 18 / 18 | 238 / 237 / 236 | below floor (0.008 / 0.007 / 0.009) | | |
| the other five routes | 10,000 / 30,000 / 70,000 | unchanged from `../diagnosis/per-request/` (8, 8, 7, 6, 0) | 222 / 229 / 217 / 199 / 0 at every count | below floor | | |

The php CPU / DB CPU ratio is recorded only; the mark's coefficient (9.11) is not changed. For the two heavy routes at 70,000 rows it is 7.89 (book page) and 1.25
(ZIP), both below 9.11. The ZIP's php CPU time at 70,000 rows is 29.98 s: with PHP's 30-second limit on, a request this size would sit at the limit.
The ZIP took 51.7 s (median of three: 51.8, 51.6, 51.7 s), all with HTTP 200, with the limit off.

(The php CPU column of rows 10,000 and 30,000 is the median of `php_cpu_s` in `probe/table.json`; the 70,000-row values are in the same field.)

## The 10,000- and 30,000-row baselines made again, beside the first ones (`../diagnosis/per-request/`)

| route | rows | rows examined, first (before ANALYZE) | rows examined, again (after ANALYZE) | statements, first / again |
| --- | --- | --- | --- | --- |
| `GET /books/{slug}` | 10,000 | 78,460 | **126,464** | 45 / 45 |
| `GET /books/{bookSlug}/export/zip` | 10,000 | 53,698 | **83,696** | 12,004 / 12,004 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 10,000 | 225 | 229 | 8 / 8 |
| `GET /shelves/{slug}` | 10,000 | 237 | 238 | 18 / 18 |
| `GET /books/{slug}` | 30,000 | 378,454 | 378,463 | 45 / 45 |
| `GET /books/{bookSlug}/export/zip` | 30,000 | 250,696 | 250,696 | 36,004 / 36,004 |

At 10,000 rows the rows examined by the book page and the ZIP export changed once the statistics were analyzed (+61% and +56%); at 30,000 rows nothing of
note changed. Statement counts are the same everywhere. The estimate's rows for these two routes at 10,000 rows (`../estimate-r3/rule/10k/`) now divide by the
new measurement as 0.984 (book page) and 0.992 (ZIP) for the point estimate (`baseline-10000/probe/table.md`).

## Estimate ÷ measurement

For 10,000 and 30,000 rows the tables are in `baseline-10000/probe/table.md` and `baseline-30000/probe/table.md`, made with
`request_probe.py report` against `../estimate-r3/rule/10k/estimate.json` and `../estimate-r3/rule/30k/estimate.json` and the committed prediction's
coefficients (`poc/lab/predictions/ladder-prediction.json`). **For 70,000 rows no estimate file exists in the repository**, so no estimate ÷ measurement ratio and
no verdict against the criteria of D182 is made here; `recheck-70000/probe/table.json` holds the measurement only (no estimate fields).

## The limits were put back

After the last run the two changed files were put back from copies kept outside the repository and the lab was recreated with `use-db 10000`.

| | before the change | with limits off | after restoring |
| --- | --- | --- | --- |
| `max_execution_time` seen through php-fpm | 30 | 0 | 30 |
| `request_terminate_timeout` (`php-fpm -tt`) | 0s | 0s | 0s |
| nginx `fastcgi_read_timeout` (`nginx -T`) | 120s | 600s | 120s |
| `zz-lab.ini` SHA-256 | f495eab5... | 4925ce15... | f495eab5... (equal to before; equal in the container) |
| `nginx/lab.conf` SHA-256 | 604aedff... | fb4856ea... | 604aedff... (equal to before; equal in the container) |
| `git status` / `git diff` of `poc/lab/compose/` | empty | 2 files changed | empty |
| `check-limits` | `--require=none`: exit 0 | `--require=unlimited --request-timeout=600s`: exit 0 | `--require=limited`: exit 0 |

The database container was not recreated at any point (started 04:55:10 UTC). Times: change 14:32:48 to 14:33:03; restore 14:56:03 to 14:56:17 (local).
