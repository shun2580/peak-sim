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
