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

(added below after the run)

### Edits made to the kept files before the commit

The home directory is written `~` and the run id of the database name `<runid>`. One value of the build's row counts, `product_attribute_values` (six rows per product), is written `6 x 30000`, and the estimated row counts of that table in `analyze/analyze-table-stats-*.tsv` are written `<over 150k>`; no other value was changed.
