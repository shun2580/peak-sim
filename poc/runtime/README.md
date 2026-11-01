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

## Layout (this part)

| Path | Role |
| --- | --- |
| `compose/` | the sealed stack, the test-only leaky stack, the PHP image, the `measure` user |
| `src/Config/` | `MeasureConfig` (environment only), `MeasureRefused` |
| `src/Guard/` | `DbGuard`, `MeasureDb`, `EgressProbe`, `RollbackGuard` |
| `bin/stack` | `up` / `down` / `ps` / `exec` for the stack |
| `bin/preflight`, `bin/cleanup`, `bin/canary-egress` | host-side entry points; the PHP half is in `bin/inner/` and runs inside the container |
| `tests/Unit/` | no Docker, no database |
| `tests/Docker/` | needs Docker; `run.sh` starts a driver container and runs them |

## Exit codes (this directory's own scheme; not the official tool's)

| Code | Meaning |
| --- | --- |
| 0 | done |
| 10 | start refused: host, user, database name or input is not allowed |
| 11 | preflight failed: the container can leave the network, or cannot reach the DB service |

## Tests

PHP is not needed on the host. From the repository root:

```bash
# Unit tests (PHP 8.2 and 8.3); the repo's dev image has PHP and vendor/
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app peak-sim-php-dev:8.3 \
  vendor/bin/phpunit -c poc/runtime/phpunit.runtime.xml --testsuite RuntimeUnit

# Docker tests: starts and tears down real stacks (it also starts a deliberately leaky one)
bash poc/runtime/tests/Docker/run.sh
```

The ordinary suite (`phpunit.xml`) never runs these; `tests/Unit/PocIsolationTest.php` keeps the PoC out of the
official tool.
