This is a rehearsal, not a test.

# Rehearsal of the lab backtest on Bagisto at 30,000 rows (the full chain, two steps per scenario)

**Not a test, not a reference value, not used for a pass or a failure.** The count of 30,000 rows was seen before (it is the largest count of the capture). Nothing here is carried into the prediction, a rule or the verdict of the lab backtest on Bagisto at its chosen count. The point is only to learn, before that backtest, whether the whole chain runs from the start to the end with the tools, the settings, the order and the way of deciding the preparation of a step that the backtest will use, and how long the backtest will take. A fault found here is a fault of a tool or of a procedure; a number found here is not looked at for its quality.

**Written 2026-11-26 14:1x, before the first request of the chain is sent.** What is above the line "Results" is fixed now and is not changed; the results are added below it.

## Which tools, and what is changed in the copy

The rehearsal runs from a throw-away clone of this repository (no remote), and **in the clone only** the three names that the Bagisto lab wrapper (`poc/lab/bin/with-bagisto`) fixes (the compose project, the name prefix of the containers, the data volume and the network, and the PHP image tag) are changed to a name of the rehearsal's own (3 lines out, 3 in; the diff was read). The state and output directory is the rehearsal's own (the wrapper offers `PEAK_LAB_BAGISTO_STATE_DIR`). The result is a second, separate stack with its own data volume, so that the Bagisto lab stack of the backtest, its volume and its running containers are not touched. Every other file of the tools is the repository's file at the commit the clone was made from, `1d1f7b9` (`git -C <clone> log`: the clone's own commits are the names, `4d31626`, and the rehearsal prediction, `ca8a477`). The same scripts run in the backtest: `bin/up`, `bin/build-db`, `bin/analyze-db`, `bin/use-db`, `bin/lab-config-b`, `bin/check-limits`, `bin/targets`, `bin/measure-ladder-chain` (which calls `bin/measure-ladder`).

The settings for B are put on the clone with `bin/lab-config-b apply` (limits off: PHP `max_execution_time` 0 and `memory_limit` 4G, the web server's `fastcgi_read_timeout` 1800 s, the load tool's request timeout 1800 s); the two files then have the same bytes as the files the Bagisto lab reads in this repository's working tree (`prep.txt`, the sha256 lines). The app side has 10 CPUs (cores 2-7 and 12-15), the database 2 (0-1), the load tool 2 (8-9), the tools 2 (10-11), as in the backtest.

## The inputs of the rehearsal prediction (made at 30,000 rows)

Made like the one of the backtest, only at the rehearsal's count (`prediction/`; the three `*.sha256` and the tool's own output are kept; **the prediction directory here is not committed on purpose: it is a rehearsal prediction, not a prediction of the backtest**):

1. a database of 30,000 rows in the shared tables (`bin/build-db --scale=30000` with the 123 N tables of `docs/research/bagisto-prep/n-tables.txt`, then `bin/analyze-db`, once);
2. the plans of the statements at that count: `bin/explain-at-scale` with the third capture of Bagisto (`capture.json`, `tasks.json`), `--scale=30000`, the small side at its default of 10,000, no memory cap, the image of the capture's build; then `bin/estimate --candidate=H1_RANGE --with-formulas --with-placeholders` (with the route-level predictions of `bin/predict --at=30000` from the committed fits of the extrapolation test) and `bin/marked-routes`, each made twice (same bytes);
3. the shared-table copy of the estimate (`docs/research/lab-data/bagisto-calibration-gate/share_n_table.py`, the same one as for the calibration), and the scenario file made by `scripts/make_input.py` from the calibration file of the eight runs (`../bagisto-calibration/fit/calibration.yaml`, copied unchanged) and the five target scenarios s-1 to s-5 at 30,000 rows over the eight routes of `poc/lab/bagisto/route-list.json`;
4. `bin/predict-ladder --drop-marked-routes --request-timeout-s=1800 --warmup-from-marks --naive-count=10000`, then `bin/prediction-gate` (open) on the file committed in the clone.

The preparation of each step is read from this file by the measuring tool (the `warmup_sec` of the stage).

## What will be measured

- **Scenarios:** all five, s-1 to s-5 (the five mixes of section 7.4.2: even, first80, last80, places1235, places1345), in this order, in one chain (`bin/measure-ladder-chain`), the app and web containers made again before each scenario, the limits read after each recreation and at every step, the memory and table statistics read before and after each scenario.
- **Steps:** the first two of each scenario, the two lowest of the ladder, **0.025 rps and 0.035 rps** (`--max-steps=2`, a setting for rehearsals; the chain's first log line says it). The same choice as the earlier rehearsal of the sixth backtest on BookStack (`../dryrun-22/`). These rates are far below any limit rps of the prediction (the prediction's limit rps for the five scenarios is 2.5 to 5.9 rps at the point), so the stopping rules of a scenario (80% utilization, a rate that does not come out) are **not exercised** here, and neither is the case in the plan of the backtest in which the application's CPUs reach 90% before the database reaches 80%; the rehearsal says nothing about them.
- **The window and the stop mark:** the scheme B (the window is the smallest multiple of 40 / rps that is at least 300 s), the first part of each step discarded for the preparation decided by the prediction, the stop mark file of the chain not used.
- **Records kept:** the app CPU use of each step (in `record.json`), the requests running at the window edges, the memory sequence, the database's log for recovery or error lines at each start, the volumes before and after, the app, web and database logs.
- **What is read at the end:** the scripts that make the verdict (`judge_b.py`, `conditions.py`, from `../dryrun-286/judge/`, unchanged) are run on the rehearsal's records only to see that the output comes out to the end. With two steps per scenario the verdict is undecidable, which is the right answer here.
- **The length of the backtest** is estimated from this rehearsal's step times (the part of a step outside the window and the preparation, the gaps) and the windows of the rules. The estimate is conditional on the backtest's own prediction, which is not made yet.

## Not done here, on purpose

- No database of the backtest's count is built, no prediction of the backtest is made or committed, nothing is measured at that count.
- Nothing about a rehearsal number is used to change a tool or a rule. A defect found is reported, not repaired here.

## Results

Added 2026-11-26 after the chain ended (the chain: 2026-11-26 14:12:49 to 18:56:16, exit 0, 4 h 43 min 27 s). Facts, not a verdict. Times are the host clock (JST); the app and web logs are in UTC.

### What the chain showed

- **All 10 steps are `valid`, `dropped 0`, the achieved rps equal to the decided rps** (0.025 and 0.035); each step had 40 requests in its window, all with status 200 (`steps.tsv`, `ladder/`). Every scenario sent 8 of 8 routes (`ALLOCATION s-N: 8 routes sent, 0 not sent`; no route is taken out of the mix at 30,000 rows). `WRITE CHECK OK` (the list allows no write) for each scenario, `TABLES SAME` for each scenario, `WRITES SAME after a step` for each step; the memory sequence has 15 readings and every one shows `app_oom=0 app_oom_kill=0 db_oom=0 db_oom_kill=0` (`chain/mem-sequence.txt`); the app and database containers' `OOMKilled` was false and `RestartCount` 0 at the end.
- **The preparation was read from the prediction file**: every scenario printed `WARMUP s-N: the discarded start of each step is read from the prediction file` and every step's record holds `warmup_sec` 231; the prediction has 231 s for all 75 stages, from the route `GET /api/products` (upper bound 230.95 s).
- **CPU use** (database and app, each step; `steps.tsv`): the database 1.15% to 1.87%, the app 0.47% to 2.36% of its 10 CPUs. The case in which the app reaches 90% before the database reaches 80% did not come up and could not: the steps are at the two lowest rates.
- **The requests running at the window edges** are 0 on every route at the start and the end of the window in all 10 steps (`running-requests.tsv`); no request outlived its window (`end_of_step`: 0 finished after the window, longest 0 ms).
- **The length of a step** (`step-times-steps.tsv`): 1,895 s at 0.025 rps (window 1,600 + preparation 231 + outside 64) and 1,433 to 1,437 s at 0.035 rps (window 1,142 + 231 + 60 to 64). The part outside the window and the preparation was 60 to 64 s (median 64). Between two steps of a scenario 2 to 3 s; between two scenarios (end of the last step to the start of the next first step) 70 s in all four; the first step started 50 s after the chain's first line (the recreation and the checks).
- **The database**: it was started once, by `bin/up`, and not restarted before or during the chain; its log has no recovery or error line before the chain or at the end (`preflight-before-chain.txt`, `chain/logs/db-final.txt`); after the chain it was stopped with a 180 s grace and the log ends with `Shutdown complete`, exit 0 (`stop-rehearsal-stack.txt`).
- **The settings of the web server and of php-fpm** read in the first step (`nginx-check/first-step-settings.txt`) have no upstream keepalive and no `fastcgi_keep_conn`, a read timeout of 1,800 s, `pm = static` with `pm.max_children = 16`, `request_terminate_timeout = 0s`, `max_execution_time` 0 and `memory_limit` 4G; the web server's reading, line by line, is the same as that of the Bagisto lab stack of the backtest, which was running then (the only difference is the two `syntax is ok` lines the recorded file has at its top).
- **The scripts for the verdict** (`judge/`) ran to the end on these records: `judge_b.py` and `conditions.py` (the committed ones, unchanged, from `../dryrun-286/judge/`) wrote their output. With two steps per scenario the verdict is **undecidable** (usable steps 0, scenarios whose limit rps was measured 0), which is the right answer here.

### The length of the backtest, from this rehearsal (`judge/estimate-length.md`; conditional)

With the rehearsal's c, gaps and the windows of the ladder, a backtest whose five scenarios take K steps each and whose preparation is W seconds takes about **9 h 34 min (K = 6, W = 231 s) to 31 h 28 min (K = 15, W = 900 s)**; the table is in the file. The rehearsal cannot say K or W for the backtest: K comes from the backtest's own limit rps and the stopping rule, W from its own prediction.

- **W can be much larger than 231 s.** If the upper bound of the longest request grows in proportion to the count (a premise; one count was seen), it grows by about 77 s per 10,000 rows and passes 900 s (the line at which the prediction tool stops, exit 39) at about 117,000 rows. The backtest's count is above that, so its prediction can stop on this line unless the route (`GET /api/products`) is taken out of the mix by the allocation at that count, or the bound is smaller than a straight line gives.
- **The pause of the operating system's updates ends at 2026-11-29 00:02 JST.** A backtest that takes about 31 hours (the longest case of the table) and starts after about 2026-11-27 16:30 would run past it; one of about 22 hours, after about 2026-11-28 01:30; one of about 13 hours, after about 2026-11-28 10:45; one of about 9.5 hours, after about 2026-11-28 14:30. Which case it is is known only after the backtest's prediction is made.

### Observed, not a defect of the tools

- The app log has `PHP Warning: mkdir(): File exists ... ScratchDir.php` lines (3 in the last scenario's log), as in the earlier rehearsals' logs; no fatal line.
- **The app container's memory at these two lowest rates is not small**: after each scenario its `memory.current` was 0.9 to 2.6 GiB and `memory.peak` 1.7 to 3.6 GiB (the limit of the container is 6 GiB, PHP's `memory_limit` 4G, 16 workers), from 40 requests per step at 30,000 rows; the machine's available memory fell from 6.4 GiB at each recreation to 3.8 to 5.5 GiB after each scenario (`chain/mem-sequence.txt`). A part of `memory.current` is the page cache of files the container read. Not looked into here. It bears on the backtest, whose count is several times larger and whose memory guard stops a scenario when the container is stopped for memory.
- `compose` was not used to stop the rehearsal stack: the containers were stopped one by one (`docker stop`), the database last with a 180 s grace. The load tool and the tools container exited with 137 (they do not end within the 30 s grace); the database, app and web containers exited with 0.
- The first call of `bin/up` stopped at once with exit 2 because the Bagisto application root was not found from the clone (the wrapper looks for it next to the repository it is in); nothing was started. It was called again with the application root given in the environment (`step-times.tsv`). The backtest, which runs from the repository itself, does not need that.
- The line `CHAIN END` of `step-times.tsv` was written by hand after the chain, at 18:56:32; the chain's own end is 18:56:16 (`chain-ends.txt`, `chain/chain.log`).

### The state left

- The rehearsal stack's containers are stopped, not removed; its data volume (`peak-dryrun25-dbdata`) and the volume of the plans' build (`dryrun25eas-n30000_measure-dbdata`) are kept. **No volume was removed: 54 before, 56 after.** The Bagisto lab stack of the backtest was taken down after the chain (its database stopped with a 180 s grace, log `Shutdown complete`, exit 0; then `compose down` without `-v`; `stop-bagisto-lab.txt`); its data volume is kept (the list of volumes is the same before and after). The settings for B were not taken off in the repository's working tree.
- Nothing of the backtest's count was built, predicted or measured. The rehearsal prediction is in `prediction/` (not committed on purpose) and in the clone's own history; the repository's prediction directory was not touched.

### Files

`preflight.txt`, `preflight-before-chain.txt`, `up-output.txt`, `build-db-output.txt`, `counts.txt`, `analyze*`, `prep.txt`, `nginx-check/`, `stop-*.txt`, `step-times.tsv` (the order of the work): the lab and the database. `prediction/`: the rehearsal prediction, its inputs' hashes and the tool's output (a copy for reading). `scripts/`: `make_input.py` (the scenario file), `run-chain.sh` (the chain), `read_steps.py` and `estimate_length.py` (the tables). `chain/`, `ladder/`: the chain's own records as the tools wrote them. `steps.tsv`, `step-times-steps.tsv`, `running-requests.tsv`: read from `ladder/`. `judge/`: the verdict scripts' output and the length estimate.

### Edits made to the kept files before the commit

The home directory is written `~` and the run id of the database name `<runid>`. One value of the build's row counts, `product_attribute_values` (six rows per product), is written `6 x 30000`, and the estimated row counts of that table in `analyze/analyze-table-stats-*.tsv` are written `<over 150k>`; no other value was changed.
In the kept records of the chain (`chain/`, `ladder/`) the same two values are written the same way (`6 x 30000`, `<over 150k>`, in the `*.tsv*` files). The only other large numbers in them are the cumulative CPU counters of the samplers (`cpu.txt`, `app-cpu.txt`; microseconds since the container started) and epoch seconds, which are not counts of rows.
