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
| `bin/predict-ladder` | the ladder prediction (`docs/simulator-spec.md` 7.2 and 7.3): for each target scenario, the utilisation at each of the 13 rps stages (point and safe side), the naive estimate, and the limit rps solved from the proportional form. Sends no request; the code is `poc/runtime/src/Simulate/Ladder/`, which calls the calibration and prediction parts of `bin/simulate` |
| `tests/run.sh` | the tests of this directory (needs Docker; refuses to run while the lab exists) |
| `routes/`, `load/`, `observe/` | the route list, the load and the observation tools (not described here) |

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
