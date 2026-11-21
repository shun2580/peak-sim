# Record of the rehearsal of B at 30,000 rows (the lab keeper's part)

**Not a test, not a reference value, not used for a pass or a failure.** The count of 30,000 rows was seen before. Nothing here is carried into a prediction, a rule or a verdict. Times are the host clock (JST, UTC+9); the app and web logs are in UTC. Facts only.

## What was done, in order

| Time (2026-11-19) | What |
| --- | --- |
| 12:25 | the lab was started (`bin/up`, sealed: `PREFLIGHT OK`; `up-output.txt`). It had been stopped; the database volume was kept |
| 12:26:16 to 12:27:00 | the 30,000-row database was built again: `bin/build-db --scale=30000 --n-tables=entities,images,references` (the same recipe as every earlier B and lab build; the old database of this count was dropped first by the builder; the list of databases before is in `databases-before-build.txt`). The app's own rebuild of a table was **not** run. Exit 0. The builder's own seconds: migrations 9.95, fill 20.65, total 31.09 (`build-db-output.txt`) |
| 12:27:44 to 12:27:49 | `SELECT COUNT(*)` of every table (`counts.tsv`), then `ANALYZE TABLE` on the 41 tables (`analyze-output.txt`: 41 `status OK`; `analyze-timing.tsv`; the statistics before and after in `analyze-table-stats-*.tsv`), then the statement counters were read once (`dbstats-baseline.tsv`) and no ANALYZE was run again |
| 12:28 | the prediction maker (another seat) was told the database was ready; the lab was not measured until the prediction was placed (12:44) |
| 12:51 to 12:54 | the rehearsal repository was made (below), B's settings were applied in it, the app and web containers were made again on it, the limits were read, the targets file for 30,000 rows was written |
| 12:55:28 to 14:36:13 | the B chain, s-1 and s-2, two steps each; the stop mark was made at 13:46:38 while s-2 was running; the chain stopped before s-3 |
| 14:36:28 to 17:07:35 | the mark was removed and the chain was started again with `s-3 s-4 s-5` |
| 17:08 to 17:09 | B's settings were taken off, the containers were made again from the real repository, the limits were read |

## The counts after the build (`counts.tsv`, and the `rows` of `build-db-output.txt`)

`entities`, `images` and `references` are 30,000 each; `references` was **not** emptied (no rebuild was run). `activities`, `deletions`, `entity_permissions`, `favourites`, `search_terms`, `slug_history`, `tags`, `views`, `watches` are 30,000 (the same 12 tables as at every scale); `entity_container_data` 18,000, `entity_page_data` 12,000, `joint_permissions` 270,000. Size 59.6 MiB (`size.txt`).

## Where the chain was run from (the gate, and why a copy)

The gate (`bin/prediction-gate`) opens only for a prediction that is committed in `poc/lab/predictions/` of the repository, and the rehearsal prediction must not be committed in the real repository. So the same tools were run from a throw-away copy of the repository, with no remote, outside the working tree (a sibling directory of the repository, `_out/run-286_dryrun/repo`, kept and not deleted):

- the copy was made with `git clone` at `797074b5fffcaa74c1424354b301b15e2869f7e2` (the real repository's `HEAD` at 12:51:29, which already held the `--max-steps` change below), then its remote was removed;
- the only commit made in the copy is `4a259b0ebfa3241fe61146dc59ae69fd55a2ae18` (12:54:02): the two rehearsal predictions of B at 30,000 rows, added to the copy's `poc/lab/predictions/` (`ladder-prediction-dryrun-b30k.json`, and `ladder-prediction-dryrun-b30k-fabricated-128M.json` that has one made-up mark). The gate was opened by both (`PREDICTION OK ... 4a259b0e...`);
- the chain was measured on **`ladder-prediction-dryrun-b30k-fabricated-128M.json`**: in it one route, `GET /books/{slug}`, is taken out of every scenario by a made-up memory mark and its share is given to the others. This is the file that makes the reallocation run (the real 30,000-row prediction drops no route). The route that is not sent never reached the load tool: every step's record has 7 routes and none is `GET /books/{slug}` (`http_status_by_route`), and every scenario printed `ALLOCATION s-N: 7 routes sent, 1 not sent (GET /books/{slug})`;
- the real repository's `poc/lab/predictions/` has no difference from `HEAD` and nothing untracked (`git status`, `git diff`: empty, read at 17:10), and its `poc/lab/compose/` had no difference at any reading (12:54, 17:09);
- the tools container (`peak-lab-tools`) kept mounting the real repository (it runs the PHP tools; the files it runs are the same as the copy's, apart from the predictions directory); the app and web containers were made on the copy's files for the time of the chain, and again on the real repository's afterwards (`restore.txt`). The app's location is given to the copy's tools with `PEAK_APP_ROOT`, since `_lab.sh` finds the app only next to the repository (`prep.txt`).

## The tool added for this: `--max-steps` (commit `797074b`, in the real repository)

`bin/measure-ladder` had no way to stop a scenario after two steps (it stops only at 80% database utilization or when a step's rps does not come out). `--max-steps=<N>` (1 to 99; default off; with no flag the tools are as they were; refused with `--trial` and for a bad or empty value, exit 2 before any container) ends the scenario after its first N steps, counting recorded steps. `bin/measure-ladder-chain --max-steps=<N>` hands it to every scenario and says it in `chain.txt`. The line `MAX-STEPS s-N: stopped after rung 2 (--max-steps=2, a rehearsal setting; this is not the stopping rule)` is printed. Tests: `tests/ladder-chain.sh` 119 passed, 0 failed, 0 skipped (13 new); `ladder-gate.sh` 129, `limits-guard.sh` 69, `write-guards.sh` 265, all 0 failed, 0 skipped (run on the changed tools).

## What was checked

| What | Result | Where |
| --- | --- | --- |
| The app and web containers are made again between scenarios | yes: 5 times (before s-1, s-2, s-3, s-4, s-5), each time a new `StartedAt` of the app container, limits read after | `chain/chain.txt`, `chain/recreate/` |
| The upper values are in every step's record | yes, 10 of 10 steps: `settings` holds `php_max_execution_time` 0, `php_memory_limit` 4G, `php_fpm_request_terminate_timeout` 0s, `nginx_fastcgi_read_timeout` 1800s, `k6_request_timeout` 1800s, read from the php-fpm and nginx side before the step (`RUNG SETTINGS` lines, `settings.json`, `settings-*.txt` in each step) | `ladder/s-N-30000/rung-0K-*/` |
| The values were read from the real side at each change | at the start (`prep-limits/`), after each recreation (`chain/recreate/check-limits-after-recreate-s-N.txt`: `LIMITS OK (require unlimited)`), before taking B off (`limits-after-b-s5/`), after taking it off (`limits-after-restore/`: 30, 512M, pool 0s, nginx 120s, `LIMITS OK (require limited)`) | `prep.txt`, `restore.txt` |
| The stop mark stops at a boundary, cleans up, and the chain can be started again | yes. The mark was made at 13:46:38, 30 s after s-2 started. s-2 was not cut: both its steps finished (14:36:11). At 14:36:13 the chain wrote `CHAIN STOP at mark before s-3 ... not run: s-3 s-4 s-5`, read the memory and the table statistics, copied the logs (`chain/logs/*-stop-before-s-3.txt`), wrote `chain.stopped`, and exited 0; nothing was killed; the lab was left up (app, web, k6, tools, database containers running, app `OOMKilled` false, 0 restarts). The mark was removed at 14:36:28 and the chain started again with `s-3 s-4 s-5`: s-3 began at 14:36:45 on a fresh app container | `chain/chain.txt`, `chain/chain.stopped`, `stop-mark-made.txt` |
| The app log is copied before the containers are made again | yes: `chain/logs/app-after-s-N.txt` (`.at` holds the time in UTC, earlier than the next container's start) for N = 1..5, and `web-after-s-N.txt` | `chain/logs/` |
| The memory guard and the record | no change in any reading: `app_oom=0 app_oom_kill=0 db_oom=0 db_oom_kill=0` in every line of `chain/mem-sequence.txt`; no step was stopped for memory | `chain/mem-sequence.txt` |
| The table and write guards | `WRITE CHECK OK`; `TABLES SAME after the load` for each scenario (`entities`, `images`, `references` at 30,000, the same checksums); `WRITES SAME after a step` for each step | `chain/s-N.output.txt` |
| Step validity | all 10 steps `valid`, `dropped 0`, achieved rps = decided rps (0.0250 and 0.0350) | `ladder/s-N-30000/ladder.json` |

## The time of each step, as measured (`started_at.txt` to `ended_at.txt` of each step)

The plan of a step under scheme B is the window plus 60 s discarded: 27 min 40 s (1,660 s) at 0.025 rps and 20 min 2 s (1,202 s) at 0.035 rps.

| Scenario | Step 1 (0.025 rps) start to end, seconds | Step 2 (0.035 rps) start to end, seconds | Scenario, from `START` to `END` |
| --- | --- | --- | --- |
| s-1 | 12:56:02 to 13:24:43, 1,721 | 13:24:45 to 13:45:49, 1,264 | 12:55:46 to 13:45:51 (50 min 5 s) |
| s-2 | 13:46:24 to 14:15:05, 1,721 | 14:15:08 to 14:36:11, 1,263 | 13:46:08 to 14:36:13 (50 min 5 s) |
| s-3 | 14:37:01 to 15:05:42, 1,721 | 15:05:44 to 15:26:48, 1,264 | 14:36:45 to 15:26:50 (50 min 5 s) |
| s-4 | 15:27:23 to 15:56:05, 1,722 | 15:56:07 to 16:17:11, 1,264 | 15:27:07 to 16:17:12 (50 min 5 s) |
| s-5 | 16:17:46 to 16:46:27, 1,721 | 16:46:29 to 17:07:33, 1,264 | 16:17:30 to 17:07:35 (50 min 5 s) |

- A step took its planned time plus 61 to 62 s (1,721 against 1,660; 1,264 against 1,202). With 40 requests each, none of them still running after the window, the load tool's end-of-step wait (1,800 s) added nothing that could be seen here; at a count where a request outlives the window it can add up to 1,800 s per step, so these are the least.
- Between two steps of a scenario: 2 to 3 s. Between two scenarios (end of one, recreation, limits read, start of the next): the recreation took 15 to 16 s (`chain.txt`) and the first step began 15 to 16 s after the next `START`.
- Total of the ten steps: 14,925 s. First chain start 12:55:28 to the last `END` 17:07:35, with the stop of 15 s in between: 4 h 12 min 7 s.

## Observed, not a defect of this rehearsal

- The app log holds `PHP Warning: mkdir(): File exists in .../Boot/ScratchDir.php on line 47` lines (s-1: 1, s-3: 7, s-4: 6 lines; none in s-2 and s-5). The same warning is in the app logs of the 90,000-row B record (`ladder-90k/logs`: 9, 3, 6, 6, 3 lines). It comes from the scratch directory being made by two requests at once and does not change a response. Not touched.
- Nothing was found in the tools, the procedure, the settings, the stop mark or the records that needed a fix; the only change to the tools is the added `--max-steps`. No rule, line, calibration or estimate rule was touched.

## What was not done

- The real 30,000-row prediction (no route dropped) was not measured; its plans were checked only by the tests (`ladder-chain.sh` 17b: an allocation that drops no route sends all 8 routes). The allocation with a route out was the one measured here.
- A was not measured here (its databases are made by its own tools, outside the lab).
- No step above 0.035 rps was measured, so no stopping rule (80% utilization, rps not coming out) was tried.

## Files

`up-output.txt`, `build-*.txt`, `databases-before-build.txt`, `counts.tsv`, `size.txt`, `analyze-*`, `dbstats-baseline.tsv`: the lab and the database. `prep.txt`, `restore.txt`, `prep-limits/`, `limits-after-b-s5/`, `limits-after-restore/`, `static.sha256.*`: the settings and the preparation. `scripts/`: the two scripts that started the chain and made the stop mark. `chain/`: the chain's own records (`chain.txt`, `chain.stopped`, `s-N.output.txt`, `mem-sequence.txt`, `stats-scenarios.tsv`, `logs/`, `recreate/`). `ladder/`: the ladder records as `measure-ladder` wrote them (`s-N-30000/`, per step `rung-0K-*/`). In the text files the home directory of the working machine is written `~`.
