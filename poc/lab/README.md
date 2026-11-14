# The lab (`docs/simulator-spec.md` section 7.2)

A closed environment in which BookStack really runs under load, so the backtest has something to be compared with. An
experiment like `poc/runtime/`, not part of the official tool. It needs Docker and the `poc/runtime/README.md` safety
conditions 1 to 5 hold here too, and condition 6 is the lab's own (below: how each one is carried over to php-fpm).

| Path | What it is |
| --- | --- |
| `compose/lab.compose.yaml` | the sealed stack: MySQL 8, BookStack under php-fpm, nginx, k6, and a container that builds databases |
| `compose/lab.leaky.override.yaml`, `compose/lab.ports.override.yaml` | TEST ONLY: laid over the stack to make it leak (an ordinary network, a published port) |
| `compose/Dockerfile.fpm`, `compose/php/`, `compose/nginx/`, `compose/mysql-conf/` | the PHP image and the settings of each server |
| `app/entry.php` | the one entry script of php-fpm (the safety conditions 2, 4, 5 and 6; below). It calls `EntryGuard` (`poc/runtime/src/LabEntry/`) for every request |
| `bin/up`, `bin/down`, `bin/ps` | start (with the closed-network check; exit 11 and nothing left running if it fails), stop, list |
| `bin/preflight` | the closed-network check, also run by `up`, `build-db` and `use-db` |
| `bin/build-db`, `bin/use-db` | build the database of one scale; point the app at one |
| `bin/predict-ladder` | the ladder prediction (`docs/simulator-spec.md` 7.2 and 7.3): for each target scenario, the utilisation at each of the 13 rps stages (point and safe side), the naive estimate, and the limit rps solved from the proportional form. The point is one estimate; the safe side takes the marks of the estimate made at the scenario's own count of rows (`--safe-estimate=<dir>`, one per count; a count with no estimate stops it). The naive estimate is chosen among the calibration scenarios at N_0 only, the count of rows given with the required `--naive-count=<rows>` (for example `--naive-count=10000`; no default, nothing is guessed from the largest count in the file): a calibration scenario at any other count (cal-4, cal-5) is no candidate, and with no scenario at N_0 it stops (exit 37). It writes, as a record that is not used for a pass or a failure, the calibration's range per scenario (each leave-one-out fit's coefficients, utilisation at every stage and limit rps). It makes no prediction when a calibration coefficient was cut to 0 or `cal-3-orig` is among the calibration scenarios. Sends no request; the code is `poc/runtime/src/Simulate/Ladder/`, which calls the calibration and prediction parts of `bin/simulate` |
| `bin/measure-ladder`, `bin/prediction-gate` | measure one prediction scenario up the rps ladder (below), behind the gate: the committed prediction file, and behind the write guards (`--statement-record=<capture.json>` is required unless `--dry-run`) |
| `bin/check-limits` | read the lab's three limits (PHP `max_execution_time`, php-fpm `request_terminate_timeout`, nginx `fastcgi_read_timeout`) and refuse (exit 13) unless they are the ones the measurement needs: `--require=unlimited --request-timeout=<k6 duration>` (PHP 0, pool 0, nginx at least the load tool's timeout), `--require=limited` (PHP 30, the mark's check), `--require=none` (read and record only). It only reads; a limit that cannot be read refuses too |
| `bin/check-writes`, `bin/check-tables` | the write guards (i) and (iii) of safety condition 6 (below). `check-writes` reads the capture's record of statements and refuses with exit 15; `check-tables` reads the N tables and the server's write counters before and after a scenario (between a ladder's steps, the counters only) and refuses with exit 16, 17 (a count of rows not seen, no query sent) or 18 (unreadable) |
| `bin/measure-calibration` | **the one entry that measures calibration runs.** Calibration is measured from this entry only. It takes the plan and the count of rows of every run of the planned set (`--run=<id>:<plan.json>`, `--run-scale`), and the runs to measure now (`--measure`). Before anything else it calls the calibration gate (`scenarios/calibration-gate`; below) and stops with exit 14, with no container touched, if the set cannot tell the two coefficients apart. For each run it then checks the closed network, reads the limits and refuses unless they are the ones the measurement needs (`bin/check-limits --require=unlimited`, exit 13), records `mysql.innodb_table_stats` before and after, checks the record of statements and the N tables (safety condition 6; `--statement-record=<capture.json>` is required), and measures with the same samplers, k6 and `summarize-run` as the ladder. `--gate-only` runs the gate and nothing else |
| `tests/run.sh` | the tests of this directory (needs Docker; refuses to run while the lab exists) |
| `tests/ladder-gate.sh` | the tests of the gate and of its place in front of `measure-ladder` (git and bash only; never touches the lab) |
| `tests/limits-guard.sh` | the tests of `bin/check-limits` and of its place in front of every step of `r-1`..`r-5` (a stand-in `docker`; the PHP tools run in throw-away containers; never touches the lab; the load tool being never called is what shows that nothing was measured) |
| `tests/calibration-gate.sh` | the tests of the calibration gate and of its place in front of `bin/measure-calibration` (a stand-in `docker`; the PHP tools run in throw-away containers; never touches the lab; the load tool being never called is what shows that nothing was measured; one test takes the gate's line out of a copy of the entry and shows that the refused set then reaches the load tool) |
| `tests/write-guards.sh` | the tests of the write guards (a stand-in `docker`; never touches the lab; the load tool being never called is what shows that nothing was measured): `bin/check-writes` on made-up records (a write to another table, a DDL statement, an incomplete record and so on are refused, exit 15), `bin/check-tables` (baseline, restart, a changed count, checksum or write counter, a counter row of zeros that appears later and is not a write, the counters-only `step` mode that reads no table, an unreadable reading: exit 16 and 18; the refusal for counts not seen: exit 17, with no query sent), and the wiring in the real `bin/measure-calibration` and `bin/measure-ladder` (each guard stops a measurement before the load tool, and each taken out of a copy no longer does; the guards' order; a calibration run whose N tables change under the load stops before the run is summarized; a ladder stops at the step after which the server's counters show a write to a table other than `views`, before that step is summarized, and finds a changed checksum at the end of the scenario, since it reads no table between steps). `tests/make-statement-record.py` makes the records |
| `tests/scenario-timeout.sh` | the tests of `load/scenario.js`'s `REQUEST_TIMEOUT` and its status counts by route, with the real k6 against a stand-in server in throw-away containers (needs Docker; never touches the lab) |
| `poc/runtime/tests/Unit/LabEntry/` | the tests of the entry's guards (no Docker needed beyond the throw-away PHP container; never touches the lab): the statement rule (`ViewsOnlyWritesTest`), the guard around a request (`EntryGuardTest`), that `app/entry.php` calls every guard, in order, and puts no transaction around a request, and that each guard taken out of a copy of it is caught (`EntryWiringTest`), and that `poc/runtime` still rolls every request back (`RuntimeKeepsRollbackTest`) |
| `routes/`, `load/`, `observe/` | the route list, the load and the observation tools (not described here) |

## Measuring a prediction scenario

```bash
poc/lab/bin/measure-ladder --scenario=t-4 --dry-run                      # plans only; no container is touched
poc/lab/bin/measure-ladder --scenario=t-4 --predictions=poc/lab/predictions/<file>.json --statement-record=<capture.json> [--switch-scale]
```

The scenarios (`t-1` to `t-5`: the count of the 8 N tables and the mix) and the ladder (13 steps from 0.05 to 5.0 rps in total, each
6 minutes at a constant arrival rate with the first minute discarded) are those of `docs/simulator-spec.md` section 7.2. A scenario is
measured step by step from the bottom up and stops after the first step that was at 80% DB utilization or more, or whose rps did not
come out (outside 5%); that step is recorded too. A step whose rps did not come out while the DB was under 80% is recorded as an
app-side stall, with the app's CPU utilization.

- **The layout of a step (`--scheme`).** `spec` (the default) is the spec as written. `A` keeps the 5-minute window and starts each
  route's stream at its own time, so each route has a chosen whole number of requests in the window and the total is exactly the
  decided rps times the window. `B` also lengthens the window to the smallest multiple of 40 / rps that is at least 300 s (a step is
  that plus the 60 s discarded). Only the layout changes: the gate, the 80% / 5% stopping rule and the record do not depend on it, and
  the record names the scheme. `--dry-run` prints the table of every step for all three, with the number of requests the window
  would count, its deviation from the decided total, the largest error of a route's count, and the smallest distance of an arrival to
  an edge of the window.
- **The gate.** Before anything else, `bin/prediction-gate` checks that the prediction file (a `.json` in `poc/lab/predictions/`) is in
  `HEAD` and has no difference from it, staged or not, and that nothing else in that directory is untracked or changed. If that cannot
  be confirmed, `measure-ladder` exits 12 and no container has been touched. `--dry-run` does not enforce it (nothing is measured).
- **The scale.** Build the database of a scale and write its targets first (`bin/build-db --scale=N --n-tables=entities,images,references`,
  `bin/targets --static=<analysis.json> --scale=N`); `--switch-scale` points the app at it with `bin/use-db`. Without it, a mismatch stops
  the run (exit 2).
- **The record.** Per step, in `<out>/<scenario>-<scale>/rung-NN-<rps>/`: `plan.json`, `summary.json` (k6), `cpu.txt` and `app-cpu.txt`
  (the DB's and the app's `usage_usec`), `run.json` (`load/summarize-run.php`), and `record.json` (the decided and achieved rps, whether it
  was within 5%, the DB and app CPU utilization, the dropped iterations, the HTTP status counts, the start and end times, the stop
  decision, and the commit of the prediction file). The record also holds the HTTP status counts by route (`http_status_by_route`) and
  the limits the step ran under (`settings`: the PHP `max_execution_time` as a request through php-fpm sees it, php-fpm's
  `request_terminate_timeout`, nginx's `fastcgi_read_timeout`, and the timeout given to k6), read in the containers before the step's
  samplers start; `settings-nginx.txt`, `settings-fpm.txt` and `settings-ini.txt` keep the raw output. A limit that cannot be read stops the
  scenario (exit 13). `ladder.json` collects the steps of the scenario. Each step prints a `RUNG START`
  and a `RUNG END` line. A restart skips the steps that already have a `record.json`.
- **The re-check (`r-1`..`r-5`, spec 7.4.1).** `--scenario=r-N` measures 5 x N_0 and 20 x N_0 with the same steps, window and stopping rule.
  It requires `--request-timeout=<k6 duration>` (600s in the spec) so that the load tool's timeout is stated and not left to its default;
  for `t-*` the option is optional and without it the load is sent as before. The limits of the lab itself (PHP, nginx) are set outside this
  tool, but a step of `r-*` does not start unless they are the ones this measurement needs: before every step `bin/check-limits
  --require=unlimited` reads them, and if PHP's `max_execution_time` or the pool's `request_terminate_timeout` is not 0, or nginx's
  `fastcgi_read_timeout` is shorter than `--request-timeout`, or any of them cannot be read, the scenario stops with exit 13 and nothing is
  measured (a switch that was forgotten would otherwise be found only after the step's load had been spent). `t-*` and a trial are read and
  recorded as before and not required to be anything. The same check can be put in front of the one-request-at-a-time probe with
  `diagnosis/request_probe.py run --require-limits=unlimited|limited`, and run by hand before any other measurement.
- **What it uses.** `load/make-plan.php`, `load/scenario.js`, `load/summarize-run.php`, `observe/sample-cpu`; the steps, the stopping rule
  and the record are in `poc/runtime/src/LabLoad/Ladder.php` (`load/ladder.php` is its command line).

A low step has few requests per route in the kept window (0.05 rps in total is 15 requests in 5 minutes), so a whole number of arrivals
per route can put its count outside the 5% of the rps check by arithmetic alone; `--dry-run` prints that count for every step.

## Measuring calibration runs

**Calibration is measured from `bin/measure-calibration` and from nowhere else.** A run measured by a hand-written driver outside this repository has skipped the gate below
and the limits guard, and is not a calibration run of this project.

```bash
# the gate alone: reads files, touches no container of the lab
poc/lab/bin/measure-calibration --estimate=<estimate dir> --run=cal-1:<plan.json> ... --run=cal-7:<plan.json> --run-scale=cal-4:3000 ... --gate-only
# measure some of the runs (the set is still given whole: the gate looks at all of it)
poc/lab/bin/measure-calibration --estimate=<estimate dir> --run=cal-1:<plan.json> ... --run=cal-7:<plan.json> --run-scale=cal-4:3000 ... \
  --measure=cal-6,cal-7 --request-timeout=600s --statement-record=<capture.json> [--switch-scale]
```

**The calibration gate** (`poc/lab/scenarios/calibration-gate`, `docs/simulator-spec.md` 7.4.1). Before a run is measured, the plans, the counts of rows and the estimate alone give the
design matrix X of the fit: one row per run, two columns, the rate of queries Q_i and the rate of rows R_i (the same numbers the fit takes, from `CalibrationPoint::rates`). Each column is
scaled to length 1 (not centred: the fit has no intercept), and the degree is the smallest singular value of that matrix: 1 when the runs are as different as can be, 0 when every run has the
same rows per query. The degree is worked out for the whole set and for every set that leaves one run out; if the smallest is below 0.5 (`CalibrationGate::MIN_DEGREE`) nothing is measured and the entry stops with exit 14. The
degrees are printed and written to `<out>/calibration-gate.json` (and copied into each measured run's directory). No utilization is read; a run whose rps is still to be searched is given at the rps it
is planned at. The tests are `poc/runtime/tests/Unit/LabScenarios/CalibrationGateTest.php` and `tests/calibration-gate.sh`.

## Layout of the stack

One network, `internal: true`, no published port. Container names are fixed so the tools can address them.

| Container | Image | CPU | Role |
| --- | --- | --- | --- |
| `peak-lab-db` (host name `measure-db`) | `mysql:8.4.7` | 2 (cores 0-1) | the database. The statistics' automatic recalculation is left at its default. The buffer pool (2 GB) holds every database of the scenarios |
| `peak-lab-app` | `peak-lab-php` (built here from `php:8.3-fpm`) | 6 (cores 2-7, shared with the web server) | php-fpm, 16 static workers, opcache on |
| `peak-lab-web` (alias `lab-web`) | `nginx:1.28-alpine` | cores 2-7 | `http://lab-web:8080` to the app's entry script; `:8081/__lab/status` (a counter of requests) and `/__lab/health` |
| `peak-lab-k6` | `grafana/k6:2.3.0` | 2 (cores 8-9) | idle; `docker exec peak-lab-k6 k6 run /load/<script>.js` |
| `peak-lab-tools` | `peak-lab-php` | cores 10-11 | builds databases; not part of the load |

The database's CPU time is read from its own cgroup: `docker exec peak-lab-db cat /sys/fs/cgroup/cpu.stat` (`usage_usec`).

## Start, stop, build

```bash
poc/lab/bin/up                                   # needs PEAK_APP_ROOT (a BookStack checkout with vendor/), or ../peak-sim-trials/bookstack
poc/lab/bin/build-db --scale=10000 --n-tables=entities,images,references
poc/lab/bin/use-db 10000                         # the app reads this scale
poc/lab/bin/down                                 # keeps the databases; --purge removes them
```

`build-db` runs the measurement's own builder (`poc/runtime/bin/inner/build-db.php`, the `bookstack` profile) and takes either
`--n-tables=a,b` (those tables get N rows, every other table the base size) or `--global` (every table N). The database is
named `peak_sim_measure_<run id>_lab_<scale>`. A long build should be started detached (`setsid nohup`).

The throwaway passwords, the run id and the app key are made once and kept in `~/.cache/peak-lab/state.env` (mode 600),
because the database volume outlives `down`. Records go to `~/.cache/peak-lab/out` (outside the repository).

## Safety conditions, carried over

1. **Closed network.** `bin/preflight` refuses (exit 11) unless every network of the project is internal, every container is on
   those networks only and none is privileged or has a published port, and, from inside each container, two public addresses
   and a public name are unreachable while the container's own peers are reachable. It runs at every start.
2. **Only measurement databases.** The same `measure` user (`poc/runtime/compose/mysql-init/10-measure-user.sql`, the one
   grant; not the `performance_schema` grants of the comparison test), and `AppBooter` fixes and proves every connection of the
   app to the measurement database on every request.
3. **Generated data only.** The builder is the measurement's generator.
4. **The app is not modified.** Mounted read-only; the container root is read-only; only `/tmp` and `/scratch` (tmpfs) are
   writable. The compiled views are kept in `/scratch` between requests (`ScratchDir::fixed`), as they are in production.
5. **Side effects are fakes.** `Fakes::install` runs on every request, as in a measurement.
6. **Writes: `views` only, GET only.** This is the lab's own wording of condition 6; `poc/runtime/README.md` keeps its rollback
   and the lab does not use it. The entry script does not wrap a request in a transaction. The only thing the lab may write is
   the page view counter, the `views` table, and only with the two statements the app uses for it (`insert into views` and
   `update views set`; each is committed at once). Only GET and HEAD are served (405 for the rest). Four guards keep it so,
   and each is called from the one place every measurement goes through:
   - (i) *before measuring*: `bin/check-writes --statements=<capture.json>` reads the capture's record of the statements of the
     eight measured routes (`statement_types`: the kind and the text with every literal taken out, counted per route) and refuses
     (exit 15) unless every route is captured, the counts add up to the route's total with nothing unaccounted, and every statement
     is a `select` or an `update`, `insert` or `delete` of `views` alone (a DDL statement, a `SET`, another table, a multi-table
     update, a schema-qualified name, an empty text or two statements in one text are refused: what cannot be judged is refused).
     The record is taken once, in the capture's own throw-away stack; the lab's measuring stack records no statements, so nothing
     here can disturb a measured time. The tool reads a file and touches no container. `bin/measure-calibration` and
     `bin/measure-ladder` call it, on the line marked `WRITE-CHECK`, before the closed-network check and before anything of the
     lab is touched. Both take a **required** `--statement-record=<capture.json>` for it (a real capture's `capture.json`; a
     `--dry-run` of the ladder and `--gate-only` of the calibration need none, nothing is measured there).
   - (ii) *at the entry*: `EntryGuard` (`poc/runtime/src/LabEntry/`), called by `app/entry.php` for every request. Every statement
     is looked at on every connection before it is sent. One that writes anything but `views`, commits implicitly (`COMMIT`,
     `BEGIN`, `SET autocommit`, DDL, `LOCK TABLES`, ...), writes on a connection other than the default one, changes a
     server-wide setting, writes a file, or carries an executable comment (`/*!`, which MySQL runs) is not sent:
     the entry leaves a latch file and the statement raises an error. Every
     later request is then refused with 503 until the lab is rebuilt. A transaction left open by a request, or a statement that
     the watch saw only after it ran (a connection it was not on), leaves the latch too.
   - (iii) *around each scenario*: `bin/check-tables` reads, outside the measured window (after the samplers have stopped, never
     while they run), two things. One is the row count and `CHECKSUM TABLE` of the N tables (`entities`, `images`, `references`;
     `views` is left out because the lab may write it); these read each table whole. The other is the server's own write counters
     per table (`performance_schema`'s `table_io_waits_summary_by_table`: inserts, updates and deletes, which count a prepared
     statement and a write that leaves every row as it was); these read no table. It stops (exit 16) if a count or a checksum
     differs, or if any table other than `views` of the measured database, in that schema or in any schema of the server other
     than its own four, has been written to. The server lists only the tables it has opened, so a counter row whose inserts,
     updates and deletes are all 0 (a table the app opened for the first time during the scenario) is left out of both readings
     before they are compared; a new row with any count above 0 is a write and stops it. A reading that cannot be taken, or a
     server with no counters for the measured database, stops it too (exit 18): it fails closed.
     The count and checksum are taken at the start and the end of a scenario only, not between steps, because reading every
     table whole would change the buffer pool from one step to the next. `bin/measure-calibration` calls it around each run
     (`TABLES-BEFORE`, baseline `<run>/tables-before.tsv`; `TABLES-AFTER`, after k6 and the samplers have stopped,
     `<run>/tables-after.tsv`). `bin/measure-ladder` calls it at the start of the scenario (`TABLES-BEFORE`, baseline
     `<scenario>/tables-before.tsv`, which is kept: a restart is compared with the state the scenario began from), after every
     step with the counters only (`TABLES-STEP`, mode `step`, `<step>/writes-after.tsv`: no table is read, and a write to any table
     other than `views` stops the scenario at that step) and at the end of the scenario with the count and checksum again
     (`TABLES-AFTER`, `<scenario>/tables-after.tsv`). A difference stops the run before it is summarized, so that run or step has
     no record. Delete the baseline when the database is rebuilt on purpose, or the restart stops with exit 16.
     It also refuses (exit 17, no query sent) to read a database of a count of rows that has not been seen yet: anything other
     than 30,000 or fewer, 70,000 and 100,000 (so 50,000 and 200,000 too) is allowed only when `--prediction` names a prediction
     file that passes `bin/prediction-gate` (committed, no difference from `HEAD`) and holds a scenario of that count. The ladder
     passes its `--predictions` file; the calibration has no such file, so it is always refused there.
   - (iv) *the method and the schema*: `app/entry.php` serves GET and HEAD only (405), and a statement that names a schema other
     than the measurement database leaves the latch after the request (every connection of the app is also fixed to the
     measurement database and proved to be, on every request: condition 2).

   What the entry's watch cannot see is a statement the app sends straight to PDO instead of through Laravel's connection;
   (iii) is what covers that, through the checksums and the server's own counters. A `delete from views` or a `replace into views` is refused at the entry although the record in
   (i) may let it pass: only the insert and the update are allowed there, and a route that needs another one stops the lab
   visibly instead of being let through. The tests are in `poc/runtime/tests/Unit/LabEntry/`: each guard taken out of a copy of
   the entry (or turned off) is caught, and `poc/runtime`'s own rollback, its checks of a request that could not be rolled back,
   and its condition 6 are tied down there so that this rule cannot get into the tool's measurement.

## What the lab cannot say

Cache behaviour of a real deployment, data that does not fit in the database's memory (the buffer pool is made large on
purpose), contention with other work, and write load. A request costs one more statement than in production: the entry
script asks the server which database it is on (the guard of safety condition 2). The view counter's writes are kept (each
is committed at once), so the rows of `views` change during a measurement; the N tables must not (guard (iii)).
