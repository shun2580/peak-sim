# Runtime measurement (proof of concept)

An experiment, not part of the official tool. It is not in the phar, not in the composer autoload, and not in the
`peak-sim` command. Everything here may change or be thrown away; a permanent version would be rebuilt in `src/`.

The idea: run the target app's routes for real against generated data at several sizes, count the queries, and judge
the growth. The static analysis says what the code can do; this measures what it did on the path it took.

## Safety conditions (all required)

1. **The app's code runs only in a container on an internal network.** `compose/measure.compose.yaml` puts the PHP
   container and the DB container on a network with `internal: true`. No port is published. `bin/preflight` refuses
   to start (exit code 11) unless the network is internal and every outside address is unreachable from inside.
2. **Only measurement databases.** Names must match `peak_sim_measure_[a-z0-9_]{8,47}`. The `measure` MySQL user can
   touch nothing else (`compose/mysql-init/10-measure-user.sql`), and `MeasureConfig`/`DbGuard`/`MeasureDb` check the
   host, the user and every name before any SQL is sent (exit code 10).
3. **Generated data only.** No existing database or dump is used.
4. **The app is not modified.** It is mounted read-only; the container root is read-only; only `/tmp` and `/out` are writable.
5. **Side effects are fakes** (done by the app booter, not by this directory's guards).
6. **Writes are rolled back after every request.** `RollbackGuard` names what cannot be rolled back; those routes are recorded, not measured.

## Running a measurement

Docker is needed on the host; PHP is not. The app must have its `vendor/` installed (the tool never installs packages
into it) and the static analysis of it (`peak-sim analyze <app> --format=json`) is the map of routes to hit.

```bash
bin/measure --app=<app dir> --static=<analysis.json> [--expected=<expected.json>] [--data=generator|seeder] \
            [--scales=100,1000,10000] [--only=/ok/,/ng/] [--profile=<name>] [--out=<dir>] [--keep-stack]
```

It brings the sealed stack up, requires `bin/preflight` to pass (exit 11 otherwise, nothing is measured), runs the driver
inside the container, and tears the stack down. The driver:

1. builds a small database, starts the app on it and turns each route of the analysis into a task: the URI with its
   `{parameters}` filled from the first row of the right table, the login (the first row of the guard's user model), and
   for a write a body built from the validation rules of its form request (an empty body without one);
2. for every data plan a route needs, in a fixed order: builds the database of one scale (the app's migrations, then the
   data), measures every route that uses it, drops it. The empty system (scale 0) comes first and is measured on the N axis;
   a route with a child table also gets a second sweep (children per parent row, scales 3, 30, 300) in databases of their own;
3. measures one route at one scale in one process (`bin/measure-one`: a warm-up, then the cold attempt that decides, then a
   warm attempt), with every request in a transaction that is rolled back; a request that could not be rolled back makes the
   database be dropped and built again, and the route is recorded as `not_rollbackable`;
4. judges each route (`Judge/`) and writes the record.

`--data=seeder` fills the databases with the verification app's own deterministic seeder (`fixtures/laravel-app` only; the
row counts are read back and compared with what was asked). `--data=generator` (the default, any app) uses the tool's
generator: a table that grows with the route gets N rows, the rest a small base, or every table N when the analysis names no
candidate table (`scope: global`).

`--profile=<name>` (generator only) adds what a person knows about one app and its schema cannot say: it reads
`profiles/<name>.php`, an array the generator takes (`values`: column values per table, `skip`: tables left empty,
`rows`: row counts). The name is recorded in `summary.json`. A verdict reached with a profile has app knowledge added to
it; report it apart from a run without. `profiles/fixtures.php` gives the verification app's orders the status `paid`,
which one of its controllers filters for.

The record (`--out`, default under `~/.cache`; keep it outside the repository): `attempts.jsonl` (every attempt),
`routes.jsonl` and `report.md` (one row per route: outcome, verdict with its scope, standing against the static analysis),
`comparison.md/json` (with `--expected`), `summary.json` (counts, seconds per database, what each build produced) and
`tasks.json` (what the inspection decided for each route, or why it could not).

What a result means: a verdict is what was measured on this input, along the path that was taken, in the scope named beside
it. The tool sets `APP_ENV=local`, calls each route once without a query string, sends only the required fields of a body,
and does not run queued listeners, so a branch that depends on any of these is not measured. A flat count on a path that
never read the scaled tables, or whose largest response equals the empty system's, is `no_info`, not O(1).

## Layout (this part)

| Path | Role |
| --- | --- |
| `compose/` | the sealed stack, the test-only leaky stack, the PHP image, the `measure` user |
| `src/Config/` | `MeasureConfig` (environment only), `MeasureRefused` |
| `src/Guard/` | `DbGuard`, `MeasureDb`, `EgressProbe`, `RollbackGuard` |
| `src/Measure/` | the driver: data planners, task builder, database build, the process runners, the record |
| `src/Run/`, `src/Boot/` | one route at one scale in one process; starting the app read-only with fakes |
| `src/Route/`, `src/Judge/`, `src/Report/` | routes, arguments, login, bodies; the growth rules and verdicts; the comparison with the static analysis |
| `src/Data/` | the data generator |
| `profiles/` | app knowledge for the generator, one file per app, chosen with `--profile=<name>` |
| `bin/measure` | the entry point (above); the driver itself is `bin/inner/measure.php`, run inside the container |
| `bin/stack` | `up` / `down` / `ps` / `exec` for the stack |
| `bin/preflight`, `bin/cleanup`, `bin/canary-egress` | host-side entry points; the PHP half is in `bin/inner/` and runs inside the container |
| `tests/Unit/` | no Docker, no database |
| `tests/Docker/` | needs Docker; `run.sh` starts a driver container and runs them |

## Exit codes (this directory's own scheme; not the official tool's)

| Code | Meaning |
| --- | --- |
| 0 | done (routes that could not be measured are in the record, not in the exit code) |
| 2 | wrong usage |
| 10 | start refused: host, user, database name or input is not allowed, or a statement named another schema |
| 11 | preflight failed (the container can leave the network, or cannot reach the DB service), or the stack could not be brought up |
| 12 | the app could not be started |
| 13 | a measurement database could not be built (migration, seeder, generator) |
| 14 | a request changed the database and it could not be rebuilt clean |

A run that stops with 10, 12, 13 or 14 still writes what it had measured; the routes it did not reach are recorded as `not_run`.

## Tests

PHP is not needed on the host. From the repository root:

```bash
# Unit tests (PHP 8.2 and 8.3); the repo's dev image has PHP and vendor/
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app peak-sim-php-dev:8.3 \
  vendor/bin/phpunit -c poc/runtime/phpunit.runtime.xml --testsuite RuntimeUnit

# Docker tests, in two stages. Stage 1 starts and tears down real stacks from outside (including a deliberately leaky one,
# and bin/measure itself); stage 2 brings one sealed stack up and runs the tests of the group `in-stack` INSIDE its container.
bash poc/runtime/tests/Docker/run.sh                 # both stages
bash poc/runtime/tests/Docker/run.sh --stage=2       # only the tests inside the stack
```

The ordinary suite (`phpunit.xml`) never runs these; `tests/Unit/PocIsolationTest.php` keeps the PoC out of the
official tool.
