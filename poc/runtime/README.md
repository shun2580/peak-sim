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
   host, the user and every name before any SQL is sent (exit code 10). One addition, for the comparison test only (D74):
   `measure` also has SELECT on exactly two tables, `performance_schema.events_statements_history_long` and
   `performance_schema.threads` (`compose/mysql-init/20-statement-history-grants.sql`), so the test can read the rows the
   server itself examined per statement. No other `performance_schema` or `sys` table, and no PROCESS or SUPER, is granted.
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

What the tool does for any app, without a profile (nothing here is knowledge about one app):

- **Link tables and polymorphic relations.** A table whose key is made of foreign keys to two or more tables, and the child
  table of a `morphMany` / `morphOne` / `morphToMany` the app declares (read from the app's models, `Measure/MorphDiscovery`),
  grows with its largest parent when the plan does not name it, and the generated rows are tied to rows that exist (the type
  column takes the parents' morph classes in turn, the id column walks their rows). Without this a route that reads through the
  link saw the same few rows at every scale. A `morphToMany` also says which table its other key (the `tag_id` of a `taggables`)
  points at; that key has no foreign key either, so it is made to walk the rows of that table too, instead of counting past its
  last row. (Not done: keeping the rows of a link in one owner, when the owner of a row is only known through another link.)
- **Packages.** An app that has `laravel/passport` gets a throw-away key pair in the configuration of the measuring process (no
  file is written; a key the app already has is left alone). An app that has Vite gets an empty asset helper, so pages whose
  front end is not built still render (`Boot/AppSupport`).
- **Values by name** (`Data/ValueFactory`): an optional column named for a secret (`*secret*`, `*recovery_codes*`) is NULL, because
  a value there switches two-factor login on; a `*_tz` text column is `UTC`.
- **Arguments.** A route argument whose name is a table (`{category}`, `{piggyBank}`) takes the first primary key of it, when
  that value does not break the argument's `where` constraint.
- **422.** A write that answers 422 is sent again with a body rebuilt from the fields the answer names (a string, then a number,
  then an array for a field that keeps failing; three rounds). The body that works is kept for the route's later scales.
- **A doubt, not a verdict.** If the response size and the rows the scaled tables gave the app (counted from the statements'
  results, `Run/RowsRead`) are the same at every scale, the route's row carries `data_not_arriving_suspected` and `data_doubt`,
  `summary.json` lists it, and `report.md` says "data may not have arrived". The judging rules are untouched by it.

A profile's `on_boot` is allowed in this proof of concept on two conditions that `Boot/OnBootWatch` checks every time it runs:
it sends no statement and opens no transaction, and it leaves the mail, notification, queue and outbound-HTTP fakes as they were.
(A statement sent straight to the PDO object, which no event reports, is outside what the check can see.)

The record (`--out`, default under `~/.cache`; keep it outside the repository): `attempts.jsonl` (every attempt),
`routes.jsonl` and `report.md` (one row per route: outcome, verdict with its scope, standing against the static analysis),
`comparison.md/json` (with `--expected`), `summary.json` (counts, seconds per database, what each build produced) and
`tasks.json` (what the inspection decided for each route, or why it could not).

What a result means: a verdict is what was measured on this input, along the path that was taken, in the scope named beside
it. The tool sets `APP_ENV=local`, calls each route once without a query string, sends only the required fields of a body,
and does not run queued listeners, so a branch that depends on any of these is not measured. A flat count on a path that
never read the scaled tables, or whose largest response equals the empty system's, is `no_info`, not O(1).

## The maintenance state of the databases (a precondition of every estimate)

Some apps keep a table by a process of their own (a rebuild or an aggregate run from the app's maintenance page or console), and the
data generator cannot write what that process writes. **No database made by this tool has such a process run on it.** Every database
made by `bin/inner/build-db.php` (so every database of `bin/measure`, `bin/capture`, `bin/variance-run`, `bin/explain-at-scale` and the
lab's `poc/lab/bin/build-db`) is built, and then ANALYZEd where the tool asks for it (`--analyze-after-build=on`, the EXPLAIN step), and
nothing else is run on it. The line `build-db.php` prints keeps the rows of every table after the build (`rows`), so a record shows what
each table held. The decision and its reason are in `docs/decisions.md` (D229, D230).

**What you must hold for an estimate to mean something:** the database the statements were captured on and the database the estimate is
about must be in the same maintenance state: the rebuild and aggregate processes of the app run on both or on neither, and `ANALYZE TABLE`
run on both. A database that had the process run on it can hold different rows in the table it rewrites, and the routes that read that
table then read a different table from the one the estimate was made from. Every prediction output carries this as part of
`estimate_basis_note`.

Some routes rewrite a table other routes read (`POST /settings/maintenance/regenerate-references` deletes every row of `references` and
rebuilds it; `PUT /books/{slug}` inserts a row of `slug_history` for every child of the book). Each request is rolled back, so the rows
return, but the optimizer's statistics do not. A profile lists such routes in `state_changing` (route, tables, why; `profiles/bookstack.php`),
and they are measured after every other route of their database, in the order listed (`RouteOrder`, used by the driver of `bin/measure` and by
`bin/variance-run`). The repeat runs several laps on one database, so after each lap it also runs `ANALYZE TABLE` on the tables those routes
rewrite, outside any request (`g<G>/restore_*.log`). The automatic recalculation of statistics is left as it was.

## An app that needs more PHP extensions

The default measurement image (`peak-measure-php:<PHP_VERSION>`) has `pdo_mysql zip bcmath intl exif pcntl`. An app whose
code needs more (an image library, an XSLT library) fails when it starts, for instance at its migrations. Give it an image
of its own instead of changing the default:

```bash
# 1. Build the image under a name of your own (needs the internet once; the libraries come from the Debian mirror that
#    the official php image already uses). The name must start with `peak-measure-` and must not be `peak-measure-php`
#    itself, so the shared default image cannot be overwritten.
PEAK_PHP_EXTENSIONS=gd,xsl PHP_VERSION=8.3 bash poc/runtime/bin/stack build-image peak-measure-php-myapp:8.3

# 2. Run the measurement in it.
PEAK_PHP_IMAGE=peak-measure-php-myapp:8.3 bash poc/runtime/bin/measure --app=<dir> --static=<analysis.json>
```

`PEAK_PHP_EXTENSIONS` is a comma-separated list of plain extension names. `gd` (with JPEG and FreeType), `xsl` and `gmp`
bring the libraries they need; any other name is handed to `docker-php-ext-install` as it is, so an extension that is
not in the official image's source tree (a PECL one) is not covered. With `PEAK_PHP_IMAGE` set the stack is brought up
without building and without pulling: if the image is not on this machine the start stops and says how to build it.
Nothing else changes: the read-only root, dropped capabilities, internal network, no published port and the
measurement-only DB credentials are settings of the compose, not of the image, and the sealed-network check
(`bin/preflight`) runs on the stack as before.

## Plans at the target size (`bin/explain-at-scale`)

A separate command, never called by `bin/measure`. It does not run the app. From a `capture.json` (see `CAPTURE_FORMAT.md`) and the
task list of the run it came from, it builds a database of the target size with the tool's generator, makes the optimizer's statistics
known, and asks the server for the plan (`EXPLAIN FORMAT=JSON`) of the representative statement of every statement type:

```bash
bin/explain-at-scale --capture=<capture.json> --tasks=<tasks.json> --app=<app dir> --scale=100000 --out=<dir> \
                     [--small-scale=10000] [--label=<name>] [--profile=<name>] [--php-image=<name>] [--keep-db] [--keep-stack]
```

For each data plan the routes need: build the database, `ANALYZE TABLE` on every table, `STATS_AUTO_RECALC=0` on the tables the
statements name, then for each statistics state (`STATS_SAMPLE_PAGES` 20 / 4 / 200, each followed by `ANALYZE TABLE`) one EXPLAIN of every
statement type. A copy of `mysql.innodb_table_stats`, `mysql.innodb_index_stats` and the table options is taken before the state is made,
after it, and after the plans were taken, so the record shows that the state came out as meant and did not move while the plans were taken.
The same statements are explained on a small database (default 10,000 rows) in the default state. `explain.json` holds, per statement
type and state, the server's own JSON and a summary (tables, access type, key, estimated rows, `filtered`), and the marks `plan_unstable`
(the plan differs between the states), `plan_changes_at_scale` (small and target differ) and `explain_unavailable` (with the reason).

Two doors, kept apart:

- A statement that comes from the capture is sent through `ExplainSender` only, which puts `EXPLAIN FORMAT=JSON` in front and gives the
  whole text to `ExplainGate` first. The gate lets ONE `EXPLAIN FORMAT=JSON` of a SELECT, WITH, INSERT, UPDATE, DELETE or REPLACE through;
  anything else (a statement without EXPLAIN, a second statement, `EXPLAIN` of something else, a `/*!` comment) is refused before it is sent,
  with exit code 10. The transport uses real prepared statements, so the server takes one statement even if the gate were walked around.
- `ANALYZE TABLE` and `ALTER TABLE ... STATS_AUTO_RECALC / STATS_SAMPLE_PAGES` are written in `StatsStatements`. It has no method that takes a
  statement, takes table names only from the database's own list, and takes the page count only from the three states. A text from the capture
  is never sent through it.

Exit codes of the command: 0 done, 2 usage, 10 refused (as above), 11 stack or preflight, 13 a database could not be built, 15 another failure of a step (`--help` lists them).

The `measure` user's privileges are not widened: its `ALL` on the measurement databases covers `ANALYZE` and `ALTER`, and it cannot read the
`mysql` schema. The copies of the statistics are therefore read, read-only, by the DB server's root account on the host side (as
`bin/variance-run` does).

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
| `profiles/` | app knowledge for the generator, one file per app, chosen with `--profile=<name>`; a profile may also say which maintenance commands rebuild a table after the build (`rebuild`, above) |
| `bin/measure` | the entry point (above); the driver itself is `bin/inner/measure.php`, run inside the container |
| `bin/capture` | a separate command: the statements of every route at 100 / 1,000 / 10,000 rows, by type, in `capture.json` (shape in `CAPTURE_FORMAT.md`); `bin/measure` never calls it |
| `bin/estimate` | a separate command: the rows each route reads at a size N by the candidates H1 to H3 of `docs/research/explain-estimate.md` (a fit per statement type, and the ratio of two EXPLAIN readings); reads `capture.json` and `explain.json` only, no database. `--candidate=H1_RANGE` gives the H1 point and, for a route with a marked statement, the upper side (the value H2 gives to the marked types). H1_RANGE also moves the point of a marked statement by two rules (`docs/research/extrapolation-test-3-causes.md`, 7.1 and 7.2): P1, a statement marked `plan_changes_at_scale` whose plan at N reads one table by a full scan takes that scan's rows as its point; P2, a marked statement whose rows per run were fitted is not put below the line carried from its largest captured scale (value x N / that scale). A statement neither rule moves keeps its H1 value byte for byte, and H1, H2 and H3 are unchanged; the upper side is never below the point. `--with-formulas` adds the formulas themselves (per statement type and per route) so `bin/simulate` can evaluate them at another size; without the flag the output is as before. `--with-placeholders` adds, per statement type, the number of values the capture's representative statement was sent with (`placeholders`: the input of the placeholder side of the `app_limit_risk` mark, read when `resources.db_placeholder_limit` is given); it only adds that key |
| `bin/marked-routes` | a separate command: the marked routes of the 200,000-row test (a route with at least one statement marked `plan_changes_at_scale` or `plan_unstable` in the EXPLAIN at that size, `docs/simulator-spec.md` section 7.1); made from `explain.json` by that rule alone, no measured value, nothing added or dropped by hand |
| `bin/explain-at-scale` | a separate command: the plans of the statements of a `capture.json` on a database of the target size, in three statistics states (above); `bin/measure` never calls it. The steps run inside the container in `bin/inner/explain-at-scale.php` |
| `src/Explain/` | the gate and the sender of the capture's statements, the fixed statistics statements, the summary and marks of plans, the report |
| `bin/simulate` | a separate command: `bin/simulate --estimate=<dir> --scenario=<scenario.yaml> --out=<dir> [--selfcheck=<file>] [--memory-accuracy=<file>]`. Reads an estimate (made by `bin/estimate ... --with-formulas`) and a scenario file (`docs/simulator-spec.md` section 3.4), puts each scenario's own table sizes into the formulas, calibrates on the calibration scenarios, and predicts the target scenarios. Writes `loaded.json` (the first stage) and `prediction.json`, and prints a table. The prediction always carries one note: the row estimates assume a database whose optimizer statistics are up to date (`ANALYZE TABLE` run after the data was built), as the lab's databases are (`estimate_basis_note` in the JSON; a `NOTE` line in the table). A target scenario's `observed` is not read here. No database; the PHP image only (no network) if the host has no PHP |
| `bin/backtest` | a separate command: `bin/backtest --estimate=<dir> --scenario=<scenario.yaml> --out=<dir>`. Writes the same prediction as `bin/simulate` first, and only then reads the `observed` of the target scenarios; writes `backtest.json` and a table: the difference in utilization, the comparison with the naive estimate, and the three items of section 7.4 B |
| `bin/selfcheck` | a separate command: checks how well the estimate holds on the user's own app (`docs/simulator-spec.md` section 8.1). One command runs capture, the plans at the target size, the estimate, a seal (sha256 of the estimate files into `estimate/seal.json` and `estimate/order.log`), a gate that lets the measurement start only when the seal is intact, `bin/measure` at the target size, three more cold tries of each marked route (`bin/variance-run`), and the judge (rule B decides, rule A is shown). Saves `selfcheck-result.json`, which `bin/simulate --selfcheck=<file>` shows as the accuracy on that app. Its exit codes are in the table below |
| `src/Simulate/` | `Load/` (the scenario file and the estimate's formulas; `TwoStage` keeps the order of the two stages), `Calibrate/` (section 5: `Calibrator::calibrate` fits the two coefficients by non-negative least squares from `CalibrationPoint`s and returns a `CalibrationResult` with the values, the range, the residual and the warnings), `Predict/` (section 6: utilization, the limit multiplier, the range, dominant routes, sensitivity, the PHP memory line, the output), `Yaml/` (a small YAML reader that needs no package) |
| `src/Backtest/` | the naive estimate, the comparison and the report of `bin/backtest`, and the reader that keeps the target's `observed` back until the prediction is written |
| `src/Selfcheck/` | `EstimateSeal` (the order, kept in code), `SelfcheckJudge` (the comparison of the extrapolation test on the user's app), `SelfcheckCommand` and `SelfcheckReport` (the PHP half of `bin/selfcheck`: seal, gate, marked, judge) |
| `bin/stack` | `up` / `down` / `ps` / `exec` for the stack; `build-image` makes a PHP image with extra extensions |
| `bin/preflight`, `bin/cleanup`, `bin/canary-egress` | host-side entry points; the PHP half is in `bin/inner/` and runs inside the container |
| `tests/Unit/` | no Docker, no database |
| `tests/Docker/` | needs Docker; `run.sh` starts a driver container and runs them |

## Exit codes (this directory's own scheme; not the official tool's)

| Code | Meaning |
| --- | --- |
| 0 | done (routes that could not be measured are in the record, not in the exit code) |
| 2 | wrong usage |
| 10 | start refused: host, user, database name or input is not allowed, or a statement named another schema, or a statement of a capture was not one `EXPLAIN FORMAT=JSON` of a data statement |
| 11 | preflight failed (the container can leave the network, or cannot reach the DB service), or the stack could not be brought up. `bin/simulate` and `bin/backtest`: no PHP on the host and no PHP image |
| 12 | the app could not be started |
| 13 | a measurement database could not be built (migration, seeder, generator) |
| 14 | a request changed the database and it could not be rebuilt clean |
| 15 | `bin/selfcheck` or `bin/explain-at-scale`: a step failed (the code of the step is in `steps.log`) |
| 20 | `bin/selfcheck`: the order (seal, then measure) was not kept. `bin/simulate` and `bin/backtest`: the scenario file cannot be read or is not in the form of the spec |
| 21-25 | `bin/simulate` and `bin/backtest`, the input stops the run: 21 `schema_version` differs, 22 a route that the estimate does not have, 23 a calibration scenario without `observed`, 24 cores missing, 25 the estimate directory cannot be read or has no formulas |
| 30 | `bin/simulate` and `bin/backtest`: the calibration cannot be made |
| 31 | `bin/simulate` and `bin/backtest`: `memory-accuracy.json` is there but not valid |
| 40 | `bin/backtest`: no target scenario has an `observed` value (the prediction is written) |
| 41 | `bin/backtest`: the order was not kept; nothing was compared |
| 42 | `bin/backtest`: the naive estimate cannot be made |

A run that stops with 10, 12, 13 or 14 still writes what it had measured; the routes it did not reach are recorded as `not_run`.

The codes 15 and 20 of `bin/selfcheck` are its own; the codes of its parts are written in `steps.log`.

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

The unit tests of the simulator are in `tests/Unit/Simulate/` (reading, calibration, prediction), `tests/Unit/Backtest/` (the naive estimate, the comparison, the order) and `tests/Unit/Selfcheck/`.
`SelfcheckScriptOrderTest` runs `bin/selfcheck` itself with stand-in parts (no Docker needed; PHP and bash are).

The ordinary suite (`phpunit.xml`) never runs these; `tests/Unit/PocIsolationTest.php` keeps the PoC out of the
official tool.
