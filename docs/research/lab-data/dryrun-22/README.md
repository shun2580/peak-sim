This is a rehearsal, not a test.

# Rehearsal of the sixth B at 30,000 rows (the preparation of each step read from the prediction)

**Not a test, not a reference value, not used for a pass or a failure.** The count of 30,000 rows was seen before. Nothing here is carried into a prediction, a rule or a verdict of the sixth B (at 180,000 rows). Times are the host clock (JST, UTC+9); the app and web logs are in UTC. Facts only. In the text files the home directory of the working machine is written `~`; the run id in database names is written `<runid>`.

## The rehearsal's tools are the real tools plus a change of names

The lab's tools have the names of the lab's containers, the compose project and the data volume written in them (`peak-lab-*`, the volume `peak-lab-dbdata` is named in the compose file and does not follow the project name). Setting the project name alone would have used the real lab's volume. So the rehearsal ran from a throw-away copy of the repository, and **in the copy only** the names were changed (`peak-lab-{db,app,web,k6,tools,dbdata}` to `peak-dryrun22-*`, the compose project name, and the defaults of the state and output directories `~/.cache/peak-lab` to `~/.cache/peak-dryrun22`, in `poc/lab/bin/*`, `compose/lab.compose.yaml` and `diagnosis/request_probe.py`). The image name `peak-lab-php` was not changed (the existing image is used). No other line was changed (66 lines out, 66 in; the diff was read; `git diff -U0` lines without a name: 0). The real tools were not changed.

| What | Value |
| --- | --- |
| The real repository's commit the copy was made from | `7b64c188277dc9d9ccd2f625c4f3607ae7bb9d40` (the last commit of the tool changes: the preparation written into the prediction, read by the measuring tool, and the requests running at the window edges recorded) |
| The copy's own commits (not in the real repository; no remote) | `82e6dbc06925619de1b2a16a7e967aaf671f36ad` the names; `5d122f8071b90bf687d9c9aa794a3f4961fffade` the rehearsal prediction |
| After the change of names | no `peak-lab-(db|app|web|k6|tools|dbdata)` is left in the copy's `poc/lab/{bin,compose,diagnosis}` (grep: 0); `compose config` shows only `peak-dryrun22` and `peak-dryrun22-*` (`peak-lab-dbdata`: 0 lines; `compose-config` was not kept, it holds the throw-away passwords) |
| The prediction gate | `PREDICTION OK` for the committed rehearsal prediction (in the copy); the real repository's prediction directory was not touched (`git status`: only the untracked earlier rehearsal's prediction directory, as before) |
| Image `peak-lab-php:8.3` before and after `up --build` | **the ID changed:** `sha256:e409f41dccbbda1c9385fb898d6cdc89e422162fa975f3b22d3da137b1ded765` to `sha256:1ef868022e1a8d6aa3b39b6752d342e3b7bda92f0f92ecf3924b95e4a9e19b33`. What was seen: every build step was `CACHED` and the base image was pinned by digest (nothing was pulled: `up-output.txt`); after the build the image's `Created` is 2026-11-09T07:20:46Z with 14 layers. The old image was no longer on the machine after the tag moved, so I could not compare the two images myself; the reviewer's reading was that the layers and `Created` are the same and the configuration differs only in the labels (the ID is that of the manifest list the builder writes, which changes at every build on this image store) |
| Volumes (`volumes.txt`) | 22 before; 23 after `up`, after the build of the database, and after the chain and the stop; the only name added is `peak-dryrun22-dbdata`; none removed (the sorted list's hash is in the file). The rehearsal's volume was kept |
| Other containers (`containers.txt`) | 13 other containers, their names and creation times: the same hash before and after. No container of the real lab was running, started or touched. Before `up` no container used CPUs 0-11 (`preflight.txt`) |
| The real lab's files | the state of the rehearsal (passwords, run id, app key) is in its own directory; the real lab's state and output directories were not read or written. The statement record and the static analysis the chain reads were the same files as before (`statement-record.sha256.*`, `static.sha256.before`; read-only) |

## What was done, in order

| Time (2026-11-21) | What |
| --- | --- |
| 15:33 | the checks before anything was made (`preflight.txt`): 22 volumes, none named `dryrun22`, no compose project of that name, nothing running |
| 15:34 | the copy was made, the names changed, the compose output read; `bin/up --build` (sealed: `PREFLIGHT OK`; `up-output.txt`) |
| 15:35:22 to 15:36:09 | the 30,000-row database was built: `bin/build-db --scale=30000 --n-tables=entities,images,references`; exit 0; the builder's own seconds in `build-db-output.txt`. The app's own rebuild of a table was not run |
| 15:41:59 to 15:42:04 | `SELECT COUNT(*)` of every table (`counts.tsv`: 41 tables; `entities`, `images`, `references` 30,000 each; the same 12 tables with 30,000), then `ANALYZE TABLE` on the 41 tables, once (`analyze-output.txt`: 41 `status OK`; `analyze-timing.tsv`: 5 s), the statistics before and after, the counters read once (`dbstats-baseline.tsv`); no ANALYZE was run again |
| 15:42 | the rehearsal prediction was made with `predict-ladder --drop-marked-routes --warmup-from-marks` from the inputs of the earlier rehearsal at 30,000 rows (`prediction/`; the estimate was not made again), and committed in the copy |
| 15:44 to 15:45 | B's settings applied in the copy, the app and web containers made again on it, `check-limits --require=unlimited` (`LIMITS OK`), the targets file for 30,000 rows (8 of 8 routes filled) (`prep.txt`) |
| 15:45:49 to 20:04:32 | the chain, s-1 to s-5, two steps each (`--max-steps=2`, a rehearsal setting), started once, no stop, exit 0 (`chain/`, `ladder/`, `scripts/run-chain.sh`) |
| 20:05 to 20:06 | the app, web and database logs were copied (`chain/logs/*-final.txt`), then B's settings were taken off in the copy, the containers made again, `check-limits --require=limited` (`LIMITS OK`), and the containers were stopped with `compose stop` (no `down`, no volume removed) (`restore.txt`) |

## What the chain showed (facts, not a verdict)

- **All 10 steps** are `valid`, `dropped 0`, the achieved rps equal to the decided rps (0.025 and 0.035). Every scenario sent 8 of 8 routes (`ALLOCATION s-N: 8 routes sent, 0 not sent`). `WRITE CHECK OK`, `TABLES SAME` for each scenario, `WRITES SAME after a step` for each step; the memory sequence shows `app_oom=0 app_oom_kill=0 db_oom=0 db_oom_kill=0` in every line (`chain/mem-sequence.txt`).
- **The preparation was read from the prediction file**: every scenario printed `WARMUP s-N: the discarded start of each step is read from the prediction file (section 7.4.5): 0.025=101s 0.035=101s ...` and every step's record holds `warmup_sec` 101. The prediction file has `warmup_sec` 101 and `warmup_basis` (the zip export route, `upper_bound_sec` 100.96) for all 75 stages (5 scenarios x 15). No route is taken out of any scenario.
- **The requests running at the window edges** are written in every step's `record.json` (`running_requests`, with `counted_by`: the load tool's sending side). At 30,000 rows they are **0 on every route at the start and at the end of the window in all 10 steps** (`running-requests.tsv`). As expected, the rehearsal does not show what a longer preparation does to the window edges: no request lasts that long at this count.
- **The length of a step** (`step-times.tsv`: `started_at` to `ended_at`): 1,761 to 1,766 s at 0.025 rps (window 1,600 + preparation 101 + outside 60 to 65) and 1,304 to 1,305 s at 0.035 rps (window 1,142 + 101 + 61 to 62). The part outside the window and the preparation (c) was 60 to 65 s, median 61, against 61 to 62 s in the earlier rehearsal. The end-of-step wait was 0 in all 10 steps. Between two steps of a scenario 2 s; between two scenarios (end of one step to start of the next) 34 to 36 s. Total of the ten steps 15,336 s; the chain from the first start to the last end 4 h 18 min 43 s.
- **The scripts for the verdict** (`judge/`) ran to the end on these records: `judge_b.py` (the committed script of the earlier rehearsal, unchanged) wrote its verdict and `conditions.py` the table; with 2 steps per scenario the verdict is **undecidable**, which is the right answer here (usable steps 0, limits measured 0); item 3 of the verdict (the "over 80% or not" agreement) reads 10 of 10 valid steps. They do not read the new `running_requests` and `warmup_sec` fields; they need not (the verdict does not use them).

## The length of the sixth B at 180,000 rows, from this rehearsal (`judge/estimate-length.md`; conditional)

`judge/estimate_length_w.py` puts the rehearsal's c, gaps and the earlier 170,000-row record's end-of-step waits into the step time = window + preparation + c + wait. The preparation for 180,000 rows is **about 573 s** if the longest request's upper bound stays linear (3.18 s per 1,000 rows; recorded at 170,000 and 300,000 rows); the real value is what the prediction writes. With the steps of the 170,000-row record as a stand-in (39 steps, **the real number comes from the prediction's limit rps and can differ**): **14 h 54 min** with the rehearsal's median c and no wait; 14 h 56 min with its largest c; **15 h 41 min** with the 170,000-row record's end-of-step waits in order. The preparation alone adds 39 x (573 - 60) = 20,007 s (5 h 33 min) over the earlier 60 s. A wait up to 1,800 s per step is possible and is not excluded. Not included: a stop and resume, a restart after a reboot, the build, the preparation before the chain.

## Observed, not a defect of this rehearsal

- The app logs hold `PHP Warning: mkdir(): File exists ... ScratchDir.php` lines (s-1 5, s-2 2, s-3 5, s-4 3, s-5 4), as in the earlier rehearsals' logs; no fatal line.
- The data and index size of the 30,000-row database read after the build was 71.3 + 103.3 = 174.6 MiB (`size.txt`); the earlier rehearsal's file says 59.6 MiB for the same count. Not looked into here; the two were read at different times and possibly with different statements.
- `compose stop` stopped the database, the load tool and the tools container with exit 137 (the compose file's stop grace is 1 s): the database was not shut down cleanly and will run crash recovery at the next start (the data volume is kept). The logs were copied before the stop.
- A script of mine was held for about 6 minutes at 15:36 by a stray command waiting for input; nothing was written to the database or to the real lab.

## What was not done

- No database of 180,000 rows was made; no prediction of the sixth B was made or committed; nothing was measured at the chosen count.
- Whether a longer preparation reduces the spill at the window edges was not seen (nothing ran at the edges at this count).
- The case with no `warmup_sec` in the prediction (the measuring tool must stop) belongs to the tests of the tools and was not repeated.

## Files

`preflight.txt`, `volumes.txt`, `containers.txt`, `up-output.txt`, `build-*.txt`, `counts.tsv`, `size.txt`, `analyze-*`, `dbstats-baseline.tsv`, `table-stats-after-build.tsv`, `databases-after-build.txt`: the lab and the database. `prep.txt`, `restore.txt`, `statement-record.sha256.*`, `static.sha256.before`: the settings and the preparation. `prediction/`: a copy of the rehearsal prediction, its inputs' hashes and the tool's output (a copy for reading; **not committed here and not a prediction of the sixth B**). `scripts/run-chain.sh`: the script that ran the chain (the last line, a note sent to another process of the working environment, is left out of this copy; the statement record's directory is written with its committed name). `chain/`: the chain's own records. `ladder/`: the ladder records as `measure-ladder` wrote them. `step-times.tsv`, `running-requests.tsv`: read from `ladder/`. `judge/`: the verdict script's output, the table of conditions, the length estimate and its script.
