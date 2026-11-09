# The lab (`docs/simulator-spec.md` section 7.2)

A closed environment in which BookStack really runs under load, so the backtest has something to be compared with. An
experiment like `poc/runtime/`, not part of the official tool. It needs Docker and the `poc/runtime/README.md` safety
conditions 1 to 6 hold here too (below: how each one is carried over to php-fpm).

| Path | What it is |
| --- | --- |
| `compose/lab.compose.yaml` | the sealed stack: MySQL 8, BookStack under php-fpm, nginx, k6, and a container that builds databases |
| `compose/lab.leaky.override.yaml`, `compose/lab.ports.override.yaml` | TEST ONLY: laid over the stack to make it leak (an ordinary network, a published port) |
| `compose/Dockerfile.fpm`, `compose/php/`, `compose/nginx/`, `compose/mysql-conf/` | the PHP image and the settings of each server |
| `app/entry.php` | the one entry script of php-fpm (the safety conditions 2, 4, 5 and 6; below) |
| `bin/up`, `bin/down`, `bin/ps` | start (with the closed-network check; exit 11 and nothing left running if it fails), stop, list |
| `bin/preflight` | the closed-network check, also run by `up`, `build-db` and `use-db` |
| `bin/build-db`, `bin/use-db` | build the database of one scale; point the app at one |
| `bin/predict-ladder` | the ladder prediction (`docs/simulator-spec.md` 7.2 and 7.3): for each target scenario, the utilisation at each of the 13 rps stages (point and safe side), the naive estimate, and the limit rps solved from the proportional form. The point is one estimate; the safe side takes the marks of the estimate made at the scenario's own count of rows (`--safe-estimate=<dir>`, one per count; a count with no estimate stops it). The naive estimate is chosen among the calibration scenarios at the largest count only. It writes, as a record that is not used for a pass or a failure, the calibration's range per scenario (each leave-one-out fit's coefficients, utilisation at every stage and limit rps). It makes no prediction when a calibration coefficient was cut to 0 or `cal-3-orig` is among the calibration scenarios. Sends no request; the code is `poc/runtime/src/Simulate/Ladder/`, which calls the calibration and prediction parts of `bin/simulate` |
| `bin/measure-ladder`, `bin/prediction-gate` | measure one prediction scenario up the rps ladder (below), behind the gate: the committed prediction file |
| `tests/run.sh` | the tests of this directory (needs Docker; refuses to run while the lab exists) |
| `tests/ladder-gate.sh` | the tests of the gate and of its place in front of `measure-ladder` (git and bash only; never touches the lab) |
| `routes/`, `load/`, `observe/` | the route list, the load and the observation tools (not described here) |

## Measuring a prediction scenario

```bash
poc/lab/bin/measure-ladder --scenario=t-4 --dry-run                      # plans only; no container is touched
poc/lab/bin/measure-ladder --scenario=t-4 --predictions=poc/lab/predictions/<file>.json [--switch-scale]
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
  decision, and the commit of the prediction file). `ladder.json` collects the steps of the scenario. Each step prints a `RUNG START`
  and a `RUNG END` line. A restart skips the steps that already have a `record.json`.
- **What it uses.** `load/make-plan.php`, `load/scenario.js`, `load/summarize-run.php`, `observe/sample-cpu`; the steps, the stopping rule
  and the record are in `poc/runtime/src/LabLoad/Ladder.php` (`load/ladder.php` is its command line).

A low step has few requests per route in the kept window (0.05 rps in total is 15 requests in 5 minutes), so a whole number of arrivals
per route can put its count outside the 5% of the rps check by arithmetic alone; `--dry-run` prints that count for every step.

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
6. **Writes are rolled back.** Every request runs in a transaction that is rolled back. Only GET and HEAD are served (405 for
   the rest). A request that could not be rolled back leaves a latch file and every later request gets 503: the database can no
   longer be trusted. (The per-table row counts a measurement compares are not taken per request here: too heavy for a load.)

## What the lab cannot say

Cache behaviour of a real deployment, data that does not fit in the database's memory (the buffer pool is made large on
purpose), contention with other work, and write load. A request costs one more statement than in production: the entry
script asks the server which database it is on (the guard of safety condition 2).
