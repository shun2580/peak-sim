# Dry run of test A at 30,000 rows: how it was measured and what was checked

**Not a test. No pass or fail uses these records, and no number here is carried into the real prediction, rules or verdict.**

## What was run

The same door and the same measuring tools as the third extrapolation test, on a small sample of routes, at 30,000 rows (a size already seen).

| Step | Command shape | Start | End | Exit |
| --- | --- | --- | --- | --- |
| Verification app, every selected route cold once | `poc/lab/bin/measure-extrapolation ... measure -- --data=seeder --scales=30000 --request-timeout=600 --warm=off` | 2026-11-19T12:30:02+09:00 | 12:33:36 (3 min 34 s) | 0 |
| BookStack, every selected route cold once | same door, `--data=generator --profile=bookstack --scales=30000 --request-timeout=600 --warm=off` | 12:33:49 | 12:45:15 (11 min 26 s) | 0 |
| BookStack, selected marked routes, 2 extra laps | same door, `repeat`, `--dbs=1 --laps=2 --stats-auto-recalc=keep --tap=off --keep-stack` | 12:45:15 | 12:55:39 (10 min 24 s) | 0 |

Routes: BookStack 8 (`GET` and `PUT /books/{slug}`, `GET /books/{slug}/references`, `GET`, `PUT` and `DELETE /shelves/{slug}`, `POST /settings/maintenance/regenerate-references`, `GET /settings/users/create`); verification app 4 (`GET /ok/eager`, `GET /ng/n-plus-one`, `POST /ok/bulk`, `GET /ng/first-or-create-in-loop`). The verification app has no marked route, so it has no repeat, as in the third test. `PUT /books/{slug}` is added to the repeat for this rehearsal only (it is not in the committed marked list) so that both routes that change a table are exercised by the repeat's ordering.

The tool is a local clone of the repository at `6ae51955765527efc0acd8f076c89f69a488d1ef` with `vendor/` copied in. Each run used its own compose project (`peak-dr6-a-fx`, `peak-dr6-a-bs`, `peak-dr6-var-a-dry30k-db1`). The lab was not touched.

Differences from the real run, all on purpose or forced:

- The gate was passed with the committed files of the third test (the gate reads git only). The rehearsal predictions for this dry run are not in `poc/lab/predictions/`, so, separately, they were committed in a second scratch clone and the gate was run on them: `PREDICTION OK` (exit 0) for all four files (estimate and marked list, both apps).
- The repeat's task list is the one the measure step wrote for this run (`bookstack/tasks.json`), not a merge of two capture task lists.
- At 30,000 rows the repeat needs far fewer minutes than at 300,000 rows. These durations are not an estimate of the real run.

## Checks

1. **A route that rewrites a table is measured after the others of its database.** In `bookstack/attempts.jsonl` (the order of the attempts) the database of the four routes that read `entities` is measured as `GET /books/{slug}`, `GET /shelves/{slug}`, `PUT /books/{slug}`, `POST /settings/maintenance/regenerate-references`: the two routes that rewrite a table come last, in the order the profile lists them. The same order is in the repeat's plan (`variance/bookstack/a-dry30k/db1/plan.json`, group g1, `restore_tables`: `slug_history`, `references`) and, with times, in `variance/bookstack/a-dry30k/db1/run.log`: lap 1 `GET /books/{slug}` 12:46:19, `GET /shelves/{slug}` 12:46:20, `PUT /books/{slug}` 12:49:04, `POST ...regenerate-references` 12:49:23, then `ANALYZE TABLE` on `slug_history` and `references` at 12:49:23 (outside any request); lap 2 the same order from 12:49:35 to 12:52:45. The routes of the other two groups (`GET /books/{slug}/references` with `PUT /shelves/{slug}`; `DELETE /shelves/{slug}`) run on their own databases.
2. **The records are in the form the verdict script reads.** `judge_a.py` was run once on these records (output not kept here): it read both applications and wrote its table to the end. Both came out undecidable, as they must with so few routes; no figure was looked at.
3. **The databases were built without the rebuild step.** `rows` in each build record of `bookstack/summary.json` (and in each group's `build.json` of the repeat) is the table count after the build. `references` holds rows after the build, not 0: 30,000 at the two points where it is a scaled table (`t35c980:30000`, `te52a32:30000`), 5 where it is not. A rebuild would have emptied it (the generator's text has no links), so the databases are in the state the real run needs. No build record has a rebuild entry.
4. **Limits.** The measuring tool runs from the command line, not through php-fpm or nginx. The memory limit is the measuring image's (`memory_limit=-1`) and the request limit is the value passed (`request_time_limit_seconds` 600, in `summary.json` `meta`). They are defined values, not values read back from a web server; the lab's server settings do not apply to test A.
5. **Teardown.** The two measuring runs removed their own stacks. The repeat was started with `--keep-stack` as in the third test; its two containers and network were removed afterwards with `docker compose down --remove-orphans` (no `-v`). No `peak-dr6` container or volume remains.

## Observations (not changed, nothing in the tools was edited)

- In `routes.jsonl`, the `class` column of a route can come from the scale-0 attempt: `GET /books/{slug}` shows `not_found` while its 30,000-row attempts in `attempts.jsonl` are `200`. The `values` and `rows` columns are from the measured scale. The third test's records have the same shape (its `not_found` count of 8).
- The measure record has no per-route time stamp, only per-database seconds in `summary.json` `timings`. The order is read from the order of `attempts.jsonl`. The repeat's `run.log` has times.
- `POST /ok/bulk` ends `exception` at 30,000 rows (`SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders`); the third test recorded it as `timeout` at 300,000 rows. This is the route's behaviour at this size, not a defect of the tools.

## Files

`fixtures/` and `bookstack/`: `routes.jsonl`, `attempts.jsonl`, `summary.json`, `tasks.json`, `report.md`. `variance/`: the repeat's record. `*.order.txt`, `*.stdout.log` (the gate's lines are first), `*.stderr.log`, `host_before_*.txt` (`free -m`), `run_fx.sh` and `run_bs.sh` (the commands that were run; the committed copies differ from the run copies only in that the completion-notice lines were replaced by a plain `echo`, the working paths were replaced by the variables `T`, `R` and `O` set by the caller, and the Japanese messages were put into English; the measure and repeat calls and their order are the same). `static-inputs.sha256`: SHA-256 of the two static-analysis inputs (the 44 MB BookStack file is not copied).
