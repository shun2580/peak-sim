# Records of the Bagisto backtest ladder at 190,000 rows: first scenario (s-1) of the second chain start

Host clock (JST; `docker logs` times are UTC, 9 hours behind). This directory is a copy of the lab's output for the first scenario (s-1, mix `even`) of the Bagisto lab backtest, scheme B (limits off, app memory limit 6 GiB). It records what was run and what was read. It does **not** compare anything with the prediction and does **not** judge pass or fail; that is done elsewhere.

## What is here

- The chain was started at 2026-11-27 02:35:55 with the flags of the rehearsal (request timeout 2400 s, `--require-allocation`) and the prediction file `poc/lab/predictions/ladder-prediction-bagisto-b.json` (committed in `87e8f6ec`; the tool printed its check at 02:25:59 as the first line of `run-20261127/chain-1/s-1.output.txt`).
- s-1 ran 7 of its 15 steps. Steps 1 to 6 ended with `k6.exit` 0 and have a `record.json`. Step 7 (rps 0.2, 06:06:53 to 06:35:09, load tool exit 0) was stopped by the tool's memory check after the step: the app container's `oom_killed` went from false to true, the cgroup `oom` counter from 0 to 208 and `oom_kill` from 0 to 3, `restart_count` stayed 0, and the app's recorded memory peak equals its limit (6,442,450,944 bytes). The tool wrote `oom-stop.txt` and **no `record.json` for step 7** (it is not judged and has no record). The chain ended at 06:35:10 with exit 19. Scenarios s-2 to s-5 were not started.
- The first start of the chain (02:33:07 to 02:34:19, exit 2) stopped before any request was sent because the file of the concrete paths for this database had not been made. Its output directory is not copied here: what exists of it is already in `../bagisto-b-prediction/second/chain-plan/` (`first-start-chain.log`, `first-start-s-1.output.txt`, `targets.txt`) and the account is in `../bagisto-b-prediction/README.md`.
- The state of the app container after the stop is in `../bagisto-b-prediction/second/chain-stop/` (committed earlier). This directory does not repeat those files; `chain-1/oom-stop-s-1/` holds the copies the chain script itself saved at the stop.
- Not stopped, not changed, not removed at the time of this copy: the lab stack, the containers, the database and the settings. This copy only read the lab's output directory.

| step | rps | started | ended | load tool exit | record |
|---|---|---|---|---|---|
| 1 | 0.025 | 02:37:13 | 03:24:06 | 0 | yes |
| 2 | 0.035 | 03:24:08 | 04:03:36 | 0 | yes |
| 3 | 0.05 | 04:03:39 | 04:37:47 | 0 | yes |
| 4 | 0.07 | 04:37:49 | 05:08:24 | 0 | yes |
| 5 | 0.1 | 05:08:27 | 05:36:22 | 0 | yes |
| 6 | 0.15 | 05:36:25 | 06:06:51 | 0 | yes |
| 7 | 0.2 | 06:06:53 | 06:35:09 | 0 | none (memory stop) |

## Files

- `run-20261127/s-1-190000/`: the ladder records as written by `measure-ladder`, unchanged except for the one path in `allocation.json` (see below): `allocation.json` (7 routes sent, `GET /admin/customers/view/{id}` not sent), `mem-baseline.txt`, `oom-baseline.txt`, `tables-before.tsv` and the files of the restart check (`tables-before.tsv.restart*`, with `.diff` empty), and per step `rung-NN-<rps>/` (`k6-output.txt`, `summary.json`, `run.json`, `record.json` for steps 1 to 6, `plan.json`, `settings*.txt` and `settings.json` read from the php-fpm and nginx side, `cpu.txt`, `app-cpu.txt`, memory and oom readings before and after, the write counters `writes-after.tsv*` for steps 1 to 6, `started_at.txt`, `ended_at.txt`, `k6.exit`). Step 7 also has `oom-stop.txt` and has no `writes-after.tsv*`, `record.json` or `run.json`.
- `run-20261127/chain-1/`: the chain script's own files: `chain-log.txt` (the chain's log), `chain-ends.txt`, `chain.done`, `s-1.output.txt` (the text the ladder printed), `mem-sequence.txt`, `stats-scenarios.tsv`, `logs/` (app and web logs saved before the recreation and after s-1), `recreate/` (the recreation before s-1 and the limits read after it), `oom-stop-s-1/` (`inspect-app.txt`, `inspect-db.txt`, `app-logs-tail.txt`, `mem-sequence.txt`).
- No `tables-after.tsv*` exists for s-1: the chain stopped before the check after the scenario.

## Changes to the copies

- The lab's `chain.log` is stored as `chain-1/chain-log.txt`, because the repository ignores `*.log`. Its content is as written, apart from the path change below.
- The home directory is written `~` in three files, and nothing else was changed in them: `chain-1/chain-log.txt` (the stop-mark path in the first line), `chain-1/s-1.output.txt` (the path in the last line) and `s-1-190000/allocation.json` (the `source` path). No other file had it.
- Nothing is kept outside the repository: the largest file is a load tool output of 1.4 MB.
- Not copied here (outside the ladder's own output): the 190,000-row static analysis file `static.json` (10,416,113 bytes, sha256 `d04395480720e547910458e9950d8aa86f0ba1f8546a5f9c747673d6ab59b8fa`) and `targets-190000.json` (2,035 bytes, sha256 `22acdd8d261363edd7b9c31bb28ff06557e2670981225f4a71e0b7141097e793`) of the lab's output directory, read on 2026-11-27 at the time of this copy; the run that made `targets-190000.json` is in `../bagisto-b-prediction/second/chain-plan/targets.txt` (8 of 8 routes filled).
