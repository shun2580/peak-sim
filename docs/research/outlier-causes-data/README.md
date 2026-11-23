# Outlier causes: measured outputs at 70,000 rows

This directory holds the measured outputs of the cause measurement of the outliers of test A, made on BookStack at **70,000 rows** (the development size; it is not one of the test sizes) and, for one route, at 100, 1,000, 10,000 and 30,000 rows (sizes the earlier capture had already used). Nothing here compares the numbers with the estimates; that is done in `../outlier-inventory.md`.

The tool copy is the one the 240,000-row run used (`../extrapolation-data-4/measured-240000/`), commit 7a4cf32 (clean tree, vendor installed). The task list `tasks.json` is byte-identical to the one that run used (sha256 `00bc2242bb87eaddb64dd0c26b8d41b5e6bb09ba3f9213babc336a5b9a574e60`). The scripts that ran the steps are in `run_scripts/`. The prediction gate (`measure-extrapolation`) was not used: 70,000 rows is not a test size and has no estimate. The tool's `variance-run` was called directly.

## What was measured, in which order

Times are the `started`/`finished` lines of each step's `run.txt` (2026-11-24, local time). Every trial of every step ended with exit 0.

| Step | Start | End | Exit | Compose project | Record |
| --- | --- | --- | ---: | --- | --- |
| 1. The 21 routes of the 240,000-row repeat, same order, three laps, tap on, no `ANALYZE` | 02:16:17 | 04:15:10 | 0 | `peak-cause70k-laps70000-db1` | `main/laps/laps70000/` |
| 2. One request at a time (5 routes), tap on, then `ANALYZE TABLE` once and the same routes once more | 02:16:18 | 02:28:17 | 0 | `peak-cause70k-single70000-db1` | `main/single/single70000/` |
| 3a. `DELETE /settings/maintenance/cleanup-images` alone at 100 rows (`ANALYZE` right after the build) | 02:30:44 | 02:31:26 | 0 | `peak-cause70k-size100-db1` | `main/size100/size100/` |
| 3b. the same at 1,000 rows | 02:31:32 | 02:32:01 | 0 | `peak-cause70k-size1000-db1` | `main/size1000/size1000/` |
| 3c. the same at 10,000 rows | 02:32:07 | 02:33:07 | 0 | `peak-cause70k-size10000-db1` | `main/size10000/size10000/` |
| 3d. the same at 30,000 rows | 02:33:12 | 02:35:29 | 0 | `peak-cause70k-size30000-db1` | `main/size30000/size30000/` |

Steps 1 and 2 ran at the same time (own project, own volume each); step 3 ran after step 2, while step 1 was still running. `run_times.txt` has the start and end of each script (a script's end includes stopping the containers).

Each step builds one database per group of routes (the tool groups the routes whose build arguments are equal) and builds only the tables its routes need (`generation.scope` `targeted:*` in `build.json`), so a group builds in 10 to 52 s even at 70,000 rows (`scale_check.txt`).

| Step | Group | Build | Routes | Trials | Tap files |
| --- | --- | ---: | --- | ---: | ---: |
| 1 | g1 (`te7dbd5`) | 13 s | 6 DELETE/POST routes (`deletions` scaled) | 18 | 18 |
| 1 | g2 (`t35c980`) | 45 s | 6 routes (`entities` and the tables around it) | 18 | 18 |
| 1 | g3 (`te52a32`) | 10 s | 8 routes (`references`) | 24 | 24 |
| 1 | g4 (`ted677c`) | 47 s | `POST /books/{bookSlug}/copy` | 3 | 0 |
| 2 | g1 (`t35c980`) | 52 s | 4 routes | 8 (4 before `ANALYZE`, 4 after) | 8 |
| 2 | g2 (`t7d53bc`) | 14 s | `DELETE /settings/maintenance/cleanup-images` | 2 (before and after `ANALYZE`) | 2 |
| 3a to 3d | g1 | 10, 10, 12, 13 s | `DELETE /settings/maintenance/cleanup-images` | 1 each | 1 each |

77 trials in all (63 + 10 + 4), 74 tap files. The three trials of `POST /books/{bookSlug}/copy` (steps 1, g4, order 61 to 63) have no tap file: the request ran longer than 600 seconds (`--request-timeout=600`) and was cut at 606 to 607 s; each `attempt.jsonl` has status 500 and each `stderr.log.txt` says "ran longer than 600 seconds" and "no cold attempt was made, so nothing was written to the tap file". The 240,000-row repeat had the same kind of result for this route. How this counts is decided by the rule of the judgement, not here.

The command of every step is `bin/variance-run` of the tool copy with `--dbs=1 --data=generator --profile=bookstack --php-image=peak-measure-php-bookstack:8.3 --stats-auto-recalc=keep --request-timeout=600 --keep-stack` and, per step: step 1 `--laps=3 --tap=on --analyze=none --routes=<the 21 routes of run_scripts/marked_routes.tsv>`; step 2 `--laps=1 --tap=on --analyze=rerun --routes=<5 routes>`; step 3 `--laps=1 --tap=on --analyze=before --routes=<the cleanup route>`. The exact arguments of each step are in its `run.txt`. Differences from the 240,000-row repeat: the row count and `--tap=on` (it was `off`); `--stats-auto-recalc=keep` and no `ANALYZE` in step 1 are the same.

## Why `--keep-stack`, and the first rehearsal

The tool copy at 7a4cf32 removes the database volume when a run ends: `bin/variance-run` line 247 `teardown() { [ "$keep" = 1 ] || compose down -v --remove-orphans ... }` and line 248 `trap teardown EXIT` (the fix that keeps the volume unless `--purge` is given is a later commit, 09514cd). So every step here is run with `--keep-stack`, which skips that trap, and the containers are stopped by the script instead (`docker stop -t 3` for the PHP container, which holds no data and ignores SIGTERM; `docker stop -t 180` for the database). None of the scripts calls `--purge`, `bin/stack down`, `compose down`, `docker rm`, `docker volume rm` or `prune`.

**The first rehearsal removed two volumes.** It was run at 1,000 rows (`dry/`, 01:59:25 to 02:02:53) without `--keep-stack`, and at its end the tool's teardown removed the database volumes of its own two projects (laps and single). It removed nothing else: `dry/volumes_check_*.txt` show `gone: none` for the volumes that existed before it (laps step 26 before and 26 after, single step 27 before and 27 after, with no added volume in either `added:` list although each step had built one). Before it, a rehearsal script failed on an unset variable before any database was touched (no record). The second rehearsal (`dry2/`, with `--keep-stack`, 02:03:50 to 02:11:30, 1,000 rows for steps 1 and 2 and 100 rows for step 3) kept its three volumes (listed below).

Before and after every step the script lists the volumes (`volumes_before_*.txt`, `volumes_after_*.txt`) and fails (exit 99) if one that existed before is gone; `volumes_check_*.txt` of all six real steps say `gone: none`. The counts: step 1 31 to 40 (it ended last, so the volumes the other steps added are in its "after"), step 2 32 to 33, 3a 33 to 34, 3b 34 to 35, 3c 35 to 37, 3d 37 to 38.

## What is kept, and what to do with it

The database volumes are anonymous in this tool copy's compose file, so the project name does not find them; `volume_ids.txt` (and `dry2/volume_ids*.txt`) record which volume belongs to which project. The containers were stopped, not removed: the database container exited with code 0 and its log has `Shutdown complete` (`docker logs` of each of the nine database containers has two `Shutdown complete` lines: the shutdown of the first start-up preparation and the last stop); the PHP container exited with 137 (it ignores SIGTERM, see above).

| Project | Volume ID (`/var/lib/mysql`) | Rows | Containers |
| --- | --- | --- | --- |
| `peak-cause70k-laps70000-db1` | `ab370d774f4291ac049440f9408a754a490a1547aea03e98cd1bb1968ae6de8c` | 70,000 | db exit 0, php exit 137 |
| `peak-cause70k-single70000-db1` | `1adeaf9f97a7cb2762425e6fc77350b0cee16de998a35d8bc884d8de023f4808` | 70,000 | db exit 0, php exit 137 |
| `peak-cause70k-size100-db1` | `b6b9fcc905426d9382686cc589c23ddf942d6b55afd96ff4cbb5da7e31beb339` | 100 | db exit 0, php exit 137 |
| `peak-cause70k-size1000-db1` | `95b3e6f8a0f2679b825c4d0db5dcf45150d2caa7ec5bcf90d87fbc043cd97343` | 1,000 | db exit 0, php exit 137 |
| `peak-cause70k-size10000-db1` | `4535300cfaee7616f9cb1f2595dcd463cb79cd45423fbb21068bfcc58c7fcd1e` | 10,000 | db exit 0, php exit 137 |
| `peak-cause70k-size30000-db1` | `94c4ef2599944cbf276a2cac0cc777643f87ee3f87db33ed6ed3a2edbaf554af` | 30,000 | db exit 0, php exit 137 |
| `peak-cause70k-dry2-laps1000-db1` (rehearsal) | `2be9b42c1ca00a44ba48a25b1d2434b696f983a38e88d286ad88b3083fb46c35` | 1,000 | db exit 0, php exit 137 |
| `peak-cause70k-dry2-single1000-db1` (rehearsal) | `a584f4982b3860da6ea7ffe8dca57a4aeea3c053f9997a9b183d4280657bbc3b` | 1,000 | db exit 0, php exit 137 |
| `peak-cause70k-dry2-size100-db1` (rehearsal) | `c0a5ca4a510f570e62f97a5c673aadb9ec5daf9274f58463958df9f7310fb7ec` | 100 | db exit 0, php exit 137 |

The container names are `<project>-measure-db-1` and `<project>-measure-php-1`. On 2026-11-24 04:32 all 18 containers were present and stopped, the nine volumes were present (`docker volume inspect`), and the host had 40 volumes. Nothing was started again, so no InnoDB recovery has run on them yet; whoever starts one should check in `docker logs` that the recovery has completed before reading it.

Removing a stopped container (`docker rm`, `compose down`) leaves its anonymous volume without a user, and `docker volume prune` removes such a volume; `compose down -v` and `docker rm -v` remove it at once. **Whether and when to remove these containers and volumes is the maintainer's decision**; nothing here removes them.

The two throwaway database passwords of the sealed stack were fixed for this machine so that a kept volume can be opened again with the same stack (the tool draws new random ones for every run otherwise). They are passed through the environment keys `PEAK_MEASURE_DB_ROOT_PASSWORD` and `PEAK_MEASURE_DB_PASSWORD`, read from two files outside the repository (mode 600). **The values are not recorded anywhere here**, and neither are the files.

## Same build in two steps

Step 1 g2 and step 2 g1 are the same group (`t35c980`, same `plan.build_args`, `--scale=70000`, targeted `entities`), on two databases built separately. Their `content.sha256` differ (`0bf37c0b…` and `ba26cb18…`). Comparing `content.tsv` (41 tables, rows and checksum): the **rows are equal in all 41 tables; the checksum differs in 4 tables** (`role_permissions`, `roles`, `settings`, `users`), and is equal in the other 37 (among them `entities`, `references`, `entity_page_data`). Why those four change at every build was not investigated. When a value of step 2 is used as a reading of step 1's database, this difference goes with it.

## Memory and host

`free -g` before each step is in `host_before_*.txt` (available memory, GB: step 1 9, step 2 9, 3a 8, 3b to 3d 9). The floor was 3 GB (a number carried over from the earlier run, not a measured need); no step was stopped by it. The containers of an unrelated build that ran in the same hours are listed in `host_before_*.txt` and were not touched.

## Files

- `main/`: the real measurement.
  - `laps/laps70000/`, `single/single70000/`, `size{100,1000,10000,30000}/size*/`: the records of the six steps. Each has `run.txt`, and `db1/` with `index.tsv`, `run.log.txt`, `watch.log.txt`, `plan.json`, `plan.groups`, `tasks.json` and one directory per group `g1`…: `build.json`, `build.seconds`, `build.stdout`, `build.stderr`, `content.tsv`, `content.sha256`, `stats_after_build.tsv`, `plan.*`, and one directory per trial (`<phase>_lap<N>/<order>_<route>/`) with `trial.json`, `attempt.jsonl`, `stderr.log.txt`, `stats_before.tsv`, `stats_after.tsv` and `tap.jsonl` (one JSON document: the window `cold_rows_examined` and, per statement, `count`, `rows_sum`, `rows_min`, `rows_max`, the first and last bindings and `EXPLAIN`). Step 2 also has `stats_before_analyze.tsv`, `stats_after_analyze.tsv`, `analyze.log.txt` and `restore_plain_lap1.log.txt`.
  - `run_times.txt`, `scale_check.txt`, `host_before_*.txt`, `volumes_before_*.txt`, `volumes_after_*.txt`, `volumes_check_*.txt`, `volume_ids.txt`.
- `dry/`, `dry2/`: the rehearsals (see above), in the same layout. Not used for any reading.
- `run_scripts/`: the scripts that ran the steps (`env.sh` shared, `run_laps.sh`, `run_single.sh`, `run_sizes.sh`, `launch.sh`, `watch_idle.sh`) and `marked_routes.tsv` (the 21 routes). `sha256.txt` there is the sha256 of the files as they were when they ran.

## What was changed when the records were copied here

- The `*.log` files are written `*.log.txt` here, because the repository does not track `*.log`. Their content is unchanged except for the replacement below. The three `launch_*.log` files were empty and are not copied.
- The scripts in `run_scripts/` differ from the files that ran: the directory name of the tool copy began with a short word naming the operator's task before a number, written here `run-` (`_scratch/run-286-t5a/rep-7a4cf32`), and the notification command and its directory (and the text of its two messages, which were not in English) are written `<notify>` and `<notify-dir>`. So their sha256 differ from `run_scripts/sha256.txt`. `env.sh` is otherwise as it ran.
- The volume lists (`volumes_before_*.txt`, `volumes_after_*.txt`, and the same lines in `dry*/`) had three volumes of other containers whose names contain a role name of the operator's side; those names are written `<other volume>`.
- Nothing else in the records was changed. The directory, project and label names of this run (`cause70k`, `main`, `dry`, `dry2`) are as they were.
- Not copied: the two files with the throwaway passwords and their values; nothing in the records contains them (checked against the files, count 0).
