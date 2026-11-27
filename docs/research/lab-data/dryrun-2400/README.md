This is a rehearsal, not a test.

# Rehearsal of the 2,400 s request timeout of the Bagisto lab's B (30,000 rows, one scenario, two steps)

**Not a test, not a reference value, not used for a pass or a failure.** The count of 30,000 rows was seen before (it is the largest count of the capture, and the count of the earlier rehearsal in `../dryrun-25/`). Nothing here is carried into the prediction, a rule or the verdict of the lab backtest on Bagisto at its chosen count. The point is only to learn, before the prediction of that backtest is made, whether the tools take a request timeout of 2,400 s from the start to the end: that the load tool, the web server's setting, the check of the limits and the chain's own log all carry 2,400 s, and that the record of a step shows the limit value. A number found here (a utilization, a rate, a time) is not looked at for its quality.

**Written 2026-11-27 01:2x, before the first request of the chain is sent.** What is above the line "Results" is fixed now and is not changed; the results are added below it.

## What is rehearsed, and why only this

The maintainer ruled (2026-11-27) that the request timeout of the Bagisto lab's B is 2,400 s instead of 1,800 s. The change is one value, in the Bagisto lab only: the web server's `fastcgi_read_timeout` on `lab-bagisto.conf` (the diff `poc/lab/config/ladder-b-2400s-bagisto.diff`), the chain's default request timeout under the Bagisto wrapper (the load tool's `REQUEST_TIMEOUT`, which is also the wait at the end of a step and the value `bin/check-limits` requires), and, as an input and not a default, the prediction's `--request-timeout-s=2400` (the line at which the prediction tool stops is half of it, 1,200 s). The unit tests of these changes ran with stand-in containers and the load tool's `inspect` only. This rehearsal runs them once against a real stack.

## Which tools, and what is changed in the copy

The rehearsal runs from a throw-away clone of this repository (no remote) made from `HEAD` at `ce51e53a`, which holds the commit that added the 2,400 s settings (`135bb585`). **In the clone only** the three names that the Bagisto lab wrapper (`poc/lab/bin/with-bagisto`) fixes (the compose project, the name prefix of the containers, the data volume and the network, and the PHP image tag) are changed to a name of the rehearsal's own, `peak-dryrun2400` (3 lines out, 3 in; the diff was read; the clone's own commit is `5f63dc32`). The state and output directory is the rehearsal's own. The result is a separate stack with its own data volume, so that the Bagisto lab stack of the backtest, its volume, the earlier rehearsal's stack and its volume are not touched. Every other file of the tools is the repository's file at that commit. The same scripts run in the backtest: `bin/up`, `bin/build-db`, `bin/analyze-db`, `bin/use-db`, `bin/lab-config-b`, `bin/check-limits`, `bin/targets`, `bin/prediction-gate`, `bin/measure-ladder-chain` (which calls `bin/measure-ladder`).

**The database is built again** (not the earlier rehearsal's volume `peak-dryrun25-dbdata`): 30,000 rows in the shared tables (`bin/build-db --scale=30000` with the 123 N tables of `docs/research/bagisto-prep/n-tables.txt`, base 5), then `bin/analyze-db`, once (`build-db-output.txt`, `analyze/`). It was built in 2 min and analyzed in 1 s; the database was started once, by `bin/up`, and its log has no recovery or error line (`db-log-recovery-error-lines-after-build.txt` holds the server's start-up warnings only; `preflight-before-chain.txt` counts 0 lines of recovery, error, crash or corruption in the whole log).

The settings for B are put on the clone with `bin/lab-config-b apply` under the Bagisto wrapper, which picks the 2,400 s diff (limits off: PHP `max_execution_time` 0 and `memory_limit` 4G, the web server's `fastcgi_read_timeout` 2400 s). The two files then have the same bytes as the two files the Bagisto lab reads in this repository's working tree (`prep.txt`, the sha256 lines). The app side has 10 CPUs (cores 2-7 and 12-15), the database 2 (0-1), the load tool 2 (8-9), the tools 2 (10-11), as in the backtest (`prep.txt`). `bin/targets` was run once for 30,000 rows with the static analysis the Bagisto lab uses (the same bytes, sha256 `d0439548...`): 8 of 8 routes filled.

After `bin/use-db 30000` the limits were read once by hand with `bin/check-limits --require=unlimited --request-timeout=2400s` (`prep.txt`): nginx 2400 s and load tool 2400 s, `LIMITS OK`.

## The inputs of the rehearsal prediction (made at 30,000 rows)

The inputs are the earlier rehearsal's, copied unchanged (the sha256 lines of `prediction/inputs.sha256` are the same as those of `../dryrun-25/prediction/inputs.sha256`): the estimate and the marked routes of the plans at 30,000 rows (each made twice in that rehearsal, same bytes), the shared-table copy of the estimate, and the scenario file (the calibration file of the eight runs, unchanged, and the five target scenarios s-1 to s-5 at 30,000 rows over the eight routes). No plan, estimate or calibration is made again; the 30,000-row database built here is used by the chain only.

The one change is the request timeout: `bin/predict-ladder --drop-marked-routes --request-timeout-s=2400 --warmup-from-marks --naive-count=10000` (the earlier rehearsal gave 1800). The prediction file's `allocation` block must then say `request_timeout_s` 2400.0 and `half_request_timeout_s` 1200.0 (the half is the line at which the prediction tool stops). It was made, and compared with the earlier rehearsal's prediction as `diff` of the two files after `python -m json.tool` (`prediction/diff-vs-earlier-rehearsal.txt`): the only differences are the commit hash of the estimate (the clone's own commit) and the two timeout fields. It was committed in the clone (`e4bb1ec`) and `bin/prediction-gate` is open on it (`prediction/prediction-gate.txt`). **The prediction directory here is not committed on purpose: it is a rehearsal prediction, not a prediction of the backtest.**

## What will be measured

- **Scenario:** s-1 only (the first of the five mixes of section 7.4.2: even), in one chain (`bin/measure-ladder-chain --scenarios="s-1"`), the app and web containers made again before the scenario, the limits read after the recreation and at every step, the memory and table statistics read before and after the scenario. **No `--request-timeout` is given:** the chain's default under the Bagisto wrapper is what is under test.
- **Steps:** the first two of the scenario, the two lowest of the ladder, **0.025 rps and 0.035 rps** (`--max-steps=2`, a setting for rehearsals; the chain's first log line says it). These rates are far below any limit rps of the prediction, so the stopping rules of a scenario (80% utilization, a rate that does not come out) are **not exercised** here.
- **The window and the stop mark:** the scheme B, the first part of each step discarded for the preparation decided by the prediction, the stop mark file of the chain not used.

## What is read at the end (and only this)

1. The chain's log (`chain/chain.log`): the value of `request_timeout` in the START line. Expected 2400s (the default under the Bagisto wrapper).
2. The load tool's `REQUEST_TIMEOUT` and `gracefulStop` of each step (the step's plan and record). Expected 2400 s and 40 m.
3. The settings record of each step (`RUNG SETTINGS`, `check-limits` output): the web server's `fastcgi_read_timeout` 2400 s, the PHP and pool limits unlimited.
4. The value `bin/check-limits` requires (`--request-timeout` 2400s) as the chain passes it.
5. The limit value written in the record of each step (`record.json`).

If 2,400 s is not taken anywhere on this path, if 1,800 s comes out anywhere, or if a tool stops, nothing is repaired here: the rehearsal stops and reports. The numbers of the steps (utilization, rate reached, times) are written down as facts and are not judged.

## Not done here, on purpose

- No database of the backtest's count is built, no prediction of the backtest is made or committed, nothing is measured at that count, and the prediction of the backtest is not started.
- The other four scenarios (s-2 to s-5) and the stopping rules are not run; they were rehearsed in `../dryrun-25/` at the 1,800 s timeout.
- Nothing about a rehearsal number is used to change a tool or a rule. A defect found is reported, not repaired here.

## Results

Added 2026-11-27 after the chain ended (the chain: 2026-11-27 01:19:42 to 02:16:21, exit 0, 56 min 39 s). Facts, not a verdict. Times are the host clock (JST); the app, web and database logs are in UTC.

### What was read at the end (the five readings of the plan)

1. **The chain's log.** `chain/chain.log` has `request_timeout=2400s` in its first line (`CHAIN START ...`) and in `START s-1 scale=30000 request_timeout=2400s max_steps=2`. No `--request-timeout` was given: this is the default of the chain under the Bagisto wrapper.
2. **The load tool.** The record of each of the two steps has `request_timeout` 2400s and `graceful_stop` 2400s (`ladder/s-1-30000/rung-*/run.json`), `end_of_step.wait_for_running_requests` 2400s, and `settings.k6_request_timeout_seen_by_k6` 2400s (`record.json`). The load tool's own output shows `gracefulStop: 40m0s` on all 16 scenario lines (8 routes, 2 steps; `k6-output.txt`), which is 2,400 s.
3. **The settings record of each step.** `RUNG SETTINGS` of both steps (`chain/s-1.output.txt`) and the step's `check-limits.out`: `php_max_execution_time` 0, `php_memory_limit` 4G, `php_fpm_request_terminate_timeout` 0s, `nginx_fastcgi_read_timeout` 2400s, `k6_request_timeout` 2400s. The web server's configuration as read in each step (`settings-nginx.txt`, copied to `nginx-check/`) has `fastcgi_read_timeout 2400s;` at line 42; `settings-fpm.txt` has `pm = static`, `pm.max_children = 16`, `request_terminate_timeout = 0s`.
4. **The value `bin/check-limits` requires.** The chain passed its request timeout to `check-limits`: `LIMITS OK (require unlimited)` with a load-tool timeout of 2400s after the recreation of the app and web containers (`chain/recreate/check-limits-after-recreate-s-1.txt`) and at each of the two steps. It also passed once by hand before the chain with `--request-timeout=2400s` (`prep.txt`).
5. **The limit values in the record of each step.** `record.json` holds `settings` with the five values of reading 3, all as above.

**1,800 s comes out nowhere:** a search of `chain/` and `ladder/` (as the tools wrote them) for `1800s`, `1800 s`, `"1800` and `=1800` found no file. **2,400 s was accepted at every point of the path, and no tool stopped.** The prediction file's `allocation` block has `request_timeout_s` 2400.0 and `half_request_timeout_s` 1200.0 (the line of the prediction tool, 1,200 s, half of the timeout).

### The two steps (facts; the quality of the numbers is not looked at)

| step | decided rps | achieved rps | status | database CPU | app CPU (of 10) | requests in the window | not 2xx | dropped | window | preparation (discarded) | started, ended |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 0.025 | 0.0250 | valid | 1.38% | 1.09% | 40 | 0 | 0 | 1,600 s | 231 s | 01:20:31, 01:52:03 |
| 2 | 0.035 | 0.03503 | valid | 1.55% | 1.50% | 40 | 0 | 0 | 1,142 s | 231 s | 01:52:05, 02:16:02 |

- Eight of eight routes were sent (`ALLOCATION s-1: 8 routes sent, 0 not sent`); `WRITE CHECK OK`, `TABLES SAME` after the scenario and `WRITES SAME` after each step. All 80 requests (5 per route per step) had status 200.
- The preparation was read from the prediction file: 231 s for every stage, as in the earlier rehearsal (the prediction's inputs are the same).
- The requests running at the window edges are 0 on every route at the start and the end of the window in both steps; 0 finished after the window (`longest_after_window_ms` 0). With these two rates no request waited at the end of the step, so **the wait of 2,400 s at the end of a step was set but not used**; a rehearsal at these rates cannot show it.
- The length of a step (start to end) was 31 min 32 s and 23 min 57 s, the same as the earlier rehearsal's within a few seconds. The gap between the two steps was 2 s; the first step started 49 s after the chain's first line (the recreation and the checks).
- The memory sequence (`chain/mem-sequence.txt`) has three readings, all with `app_oom=0 app_oom_kill=0 db_oom=0 db_oom_kill=0`: after the scenario the app container's `memory.current` was 1.73 GiB and `memory.peak` 2.44 GiB (limit 6 GiB); the machine's available memory went from 10.1 GiB at the recreation to 8.3 GiB. The app, web and database containers' `OOMKilled` was false and `RestartCount` 0 at the end.
- The database was started once, by `bin/up` (2026-11-27 01:13:56), and not restarted; its log has no recovery, error, crash or corruption line (count 0 over the whole log, before the chain and after it). The one `[Warning]` of interest in it is the redo log checkpointer lagging at 01:16:44 (during the build and the analyze, before the chain; `db-log-recovery-error-lines-after-build.txt` lists the start-up warnings).
- The app log has `PHP Warning: mkdir(): File exists ... ScratchDir.php` lines (7), as in the earlier rehearsals' logs; no fatal line.

### The state left

- The rehearsal stack was stopped container by container (`docker stop`, no `compose down`): the load tool and the tools container exited with 137 (they do not end within the 30 s grace), the web, app and database containers with 0; the database last with a 180 s grace, and its log ends with `Shutdown complete` (`stop-rehearsal-stack.txt`). The containers are stopped, not removed.
- **No volume was removed:** 58 volumes before the chain and 58 after the stop, the sorted list has the same sha256 (`preflight-before-chain.txt`, `stop-rehearsal-stack.txt`); 57 at the start of the rehearsal, the new data volume of this stack being the one added. No container is running. The Bagisto lab stack of the backtest, the earlier rehearsal's stack and the BookStack lab were not touched. The settings for B stay on this repository's working tree as they were (the rehearsal applied them in the clone only).
- Nothing of the backtest's count was built, predicted or measured, and the prediction of the backtest was not started. The rehearsal prediction is in `prediction/` (not committed on purpose) and in the clone's own history (`e4bb1ec`); the repository's prediction directory was not touched.

### Files

`preflight.txt`, `preflight-before-chain.txt`, `up-output.txt`, `build-db-output.txt`, `analyze*`, `prep.txt`, `nginx-check/`, `stop-rehearsal-stack.txt`, `step-times.tsv` (the order of the work): the lab and the database. `prediction/`: the rehearsal prediction, its inputs' hashes, the tool's output and its difference from the earlier rehearsal's (a copy for reading). `scripts/run-chain.sh`: the chain. `chain/`, `ladder/`, `chain-ends.txt`: the chain's own records as the tools wrote them.

### Edits made to the kept files before the commit

The home directory is written `~` and the run id of the database name `<runid>`. One value of the build's row counts, `product_attribute_values` (six rows per product), is written `6 x 30000`, and the estimated row count of that table in `analyze/analyze-table-stats-*.tsv` and `chain/stats-scenarios.tsv` is written `<over 150k>`; no other value was changed. The same two values are written the same way in the kept records of the chain (`chain/`, `ladder/`, the `*.tsv*` files, so the files compared by the chain stay equal). The other long digit strings in them are not counts of rows: the checksum column (the third) of `tables-*.tsv`, cumulative CPU counters and epoch seconds of the samplers, memory readings in bytes and KiB, the microsecond fields of `run.json`, and digits inside hashes and commit ids.


## Note added on 2026-11-27 (after the measurement)

The `prediction/` folder of this rehearsal was left out of version control when the rehearsal was recorded (see the files section above). On 2026-11-27 it was committed here as a record, **long after every measurement of the real rounds and of this rehearsal**. It is not used to claim any order in time, and the file in it is not a prediction of the backtest. The prediction gate reads only `poc/lab/predictions/`, which this commit does not touch. The files were not changed, and no home directory, user name, host name or secret-shaped value is in them (checked before the commit). The reason is in `docs/decisions.md` D286.
