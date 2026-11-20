# Test A, fourth run: measured outputs at 240,000 rows

This directory holds the measured outputs of the fourth run of test A (240,000 rows). The estimates are in `../fixtures` and `../bookstack` (committed at f52f692, 2026-11-19 17:40:41). The tool copy is at commit 7a4cf32 (a descendant of f52f692). Nothing here compares the numbers with the estimates; that is done in the later steps.

## What was measured, in which order

| Step | Start | End | Exit | Notes |
| --- | --- | --- | ---: | --- |
| Verification app, every route cold once | 2026-11-20 06:46:30 | 2026-11-20 08:15:32 | 0 | `fixtures/` |
| BookStack, marked routes, three laps (the repeat) | 2026-11-20 06:47:35 | 2026-11-20 11:08:51 | 0 | `variance/`; ran at the same time as the verification app and the first BookStack round |
| BookStack, 31 items cold once, first round (project `peak-t5a-bs`) | 2026-11-20 08:15:32 | cut at 2026-11-20 11:12:03 | 255 | **Not used.** Cut by a restart of the machine; kept in `_interrupted_1112/` |
| BookStack, 31 items cold once, second round (project `peak-t5a-bs2`) | 2026-11-20 23:31:51 | 2026-11-21 03:03:18 | 0 | `bookstack/`; ran alone |

The first request of the whole run was made after f52f692 (2026-11-19 17:40:41). The second BookStack round started on a new database in a new project; the database of the cut round was not reused.

## The restart that cut the first BookStack round

The Windows System log (event 1074) shows two planned restarts started by Windows Update: 2026-11-20 11:12:02 (MoUsoCoreWorker.exe, "Service pack") and 11:14:13 (TrustedInstaller.exe, "Operating system: Upgrade"). The first BookStack round ended with exit 255 at 11:12:03 and wrote only `static.json` (not kept here, 45 MB). The machine was down until about 22:42.

What was kept and what was reused:

- The containers and the mysql volume of project `peak-t5a-bs` (the cut round) were left as they are (no `volume rm`, `down -v` or prune). They were not used for any measurement.
- The verification app round and the BookStack repeat had ended before the restart, with the same predictions and the same tool commit (7a4cf32), independent of the cut round. They are reused as they are. A restart came in the time between them and the second BookStack round.
- The database of the second BookStack round was built without `regenerate-references` (D230), as the capture and the estimate. Its containers and its database volume were removed by the tool's own teardown when the run ended (`bin/measure` runs `compose down -v` on its own project unless `--keep-stack` is given), so no volume of `peak-t5a-bs2` remains.
- Before the second round the sha256 of the four prediction files in the tool copy was compared with the blobs at f52f692: all four are the same (`prediction_sha256_before_redo.txt`). `PREDICTION OK` is in `bookstack.stdout.log`.

## Counts of what the tool reported (no comparison with the estimates)

| | Routes tried | Outcome classes |
| --- | ---: | --- |
| Verification app (`fixtures/`) | 24 | ok 22, runner_failed 1 (`GET /ng/first-or-create-in-loop`, exit 139), timeout 1 (`POST /ok/bulk`, 600 s) |
| BookStack (`bookstack/`) | 63 | ok 20, unauthenticated 21, not_found 9, exception 7, validation_422 6 |

BookStack attempts: 202 (status 200: 44, 302: 76, 401: 44, 404: 14, 422: 14, 500: 10). `GET /books/{slug}` answered 500 with SQLSTATE 1390 (a prepared statement with too many placeholders), as it was left out of the allocation in B for the same reason. In the repeat, `POST /books/{bookSlug}/copy` ran 610 to 626 s per try (request limit 600 s) and counted no ok attempt. These are recorded as they came; how they count is decided by the rule of the judgement, not here.

## Files

- `fixtures/`, `bookstack/`: outputs of the two cold-once measurements (`report.md`, `summary.json`, `routes.jsonl`, `attempts.jsonl`, `tasks.json`; `static.json` of the verification app only).
- `variance/`: the BookStack repeat (`run.txt`, `index.tsv`, `run.log`, `watch.log`, the laps in `db1/g1` to `g4`).
- `order_record.txt`, `run_times.txt`, `*.wall.txt`, `*.create_options.tsv`, `host_before_*.txt`, `pre_start*.txt`: the order of the steps, the commands, the table options, and the memory and the containers before each step. No periodic memory log (`memavail.tsv`) exists for this run: `memlog.sh` was started without `T5A_OUTROOT`, so it looked for its output directory in a path that did not exist and wrote nothing. The memory was read by hand during the second round (available 10.2 to 11.0 GB).
- `prep_diffs.txt`: the difference of the tool copy from the capture tools (6ae5195 for BookStack, 8f4cd65 for the verification app), counted by directory, made on 2026-11-19.
- `run_scripts/`: the scripts that ran the steps. `sha256.txt` there is the sha256 of the files as they were when they ran; the copies here have the home directory, the user name and the host name replaced by `<home>`, `<user>` and `<host>`, so their sha256 differ.
- `_interrupted_1112/`: the cut first BookStack round (not used), with `NOTE.txt`.
- The directory and label names in `run_scripts/`, in `*.wall.txt` and in `_interrupted_1112/bookstack.wall.txt` began with a short word naming the operator's task before a number. Here that word is written `run-` (the number and the rest of the name are kept: `_scratch/run-286-t5a/`, `_out/run-286_t5a/`, `_out/run-271/`), so these names differ from the paths the runs really used, and the sha256 of the files changed in this way differ from the sha256 of the files as they ran.
