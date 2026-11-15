# Can the extrapolation test (A) build its databases at 300,000 rows? (builds and counts, nothing else)

A check made before any estimate or prediction at 300,000 rows. The test-A builder (`poc/runtime/bin/inner/build-db.php`, the
per-route way of growing the tables: a candidate table grows to N, the rest keep a small base) was run at 300,000 rows for both
applications of the test, in sealed stacks of their own. **No request was sent to the application, no EXPLAIN was run and no load was
applied**; the only data statements are the row counts the builder reads back (`rows` in each output) and the size reads from
`information_schema` (table metadata).

## Result

| Build | Application | Recipe | Wall clock | Size (`information_schema`: data / index / total) | Counts |
| --- | --- | --- | --- | --- | --- |
| 1 | BookStack | targeted, `entities` only | 187 s (01:08:55 to 01:12:02) | not read (stack was brought down for the later builds) | 300,000 in the grown tables, base 5 elsewhere (`build-bookstack-entities-only-output.json`) |
| 2 | BookStack | targeted, `entities,images,references` | 209 s (01:12:02 to 01:15:31) | not read | same, images and references also 300,000 (`build-bookstack-entities-images-references-output.json`) |
| 3 | BookStack | global (every table N) | stopped at 01:19:02 on purpose: the limit of 3,600 s was seen to be too low (D200); stack taken down with `compose down -v`, no output | none | none |
| 4 | BookStack | global, again after D200 (limit 7,200 s, `5fc6796`) | **4,366 s** (01:20:35 to 02:33:21; the builder's own fill 4,350.57 s, total 4,365.54 s) | 1,437.0 / 1,376.1 / **2,813.1 MiB**; 3,382 MiB for the database directory (`du -sm`) | every table at 300,000 rows except the fixed-content ones (`build-bookstack-global-output.json`, `rows`); `joint_permissions` 4,199,975 |
| 5 | verification app | global | **44 s** (02:38:33 to 02:39:17; fill 20.53 s) | 75.2 / 43.2 / **118.4 MiB**; 183 MiB directory | `customers`, `items`, `orders`, `product_stats`, `products` each 300,000 (`build-verification-app-global-output.json`) |

Builds 1 to 3 were made in one script run, and build 4 in a second run of the same stack setup (`bookstack-build-log.txt`); the
verification app was built in a stack of its own (`verification-app-build-log.txt`). Every build exited 0 apart from the one stopped
on purpose. As at every scale, the builder reports `role_permissions` and `permission_role` as the two BookStack tables it cannot generate.

## Where A is built

A is built in a **separate compose project** (`peak-measure-t1-300k` for BookStack, `peak-measure-t1-fx` for the verification app), with its
own database container and volume, on the **same host and the same Docker daemon** as the lab (`peak-lab-*`). It is not the lab's database
server. The two stacks were brought down with `compose down -v` after the builds (no kill command), so none of these databases exists now;
the test itself builds them again. Because the host is shared, A and the lab must not be run at the same time when the database CPU time is
being measured (D199 (6)).

## What the whole of A would take to build

| Part | Basis | Seconds |
| --- | --- | --- |
| BookStack, global database | measured (build 4) | 4,366 |
| BookStack, the other 12 per-route shapes | 12 builds of 187 to 209 s each (builds 1 and 2; the count of 12 shapes is from the 200,000-row run's summary) | about 2,250 to 2,510 |
| Verification app | measured (build 5) | 44 |
| Total of builds | | about 6,700 to 6,900 s, about 1.9 hours |

The 12 shapes were not all built (a shape's time was measured on two of them, not on every one), so the second row is an extrapolation from
two measured builds; the other two rows are measured. The test's measuring time comes on top of this and is not part of this check. The
total is well under half a day, so the build can go on.

## The lab at the end of this task

The lab was not changed by this task: its application still points at the 10,000-row database (`PEAK_LAB_SCALE=10000`) and `git diff` of
`poc/lab/compose` is 0 lines. The values read through php-fpm at 2026-11-16 02:38 (`bin/check-limits --require=limited`): `max_execution_time`
30, `memory_limit` 512M, php-fpm `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 120s (`lab-limits-at-end.json`,
`lab-fpm-ini-at-end.json`; the probe is one request through php-fpm that opens no database connection).

## Files

- `build-*-output.json`: the builders' outputs, unedited (database names carry the throwaway run id of each stack).
- `bookstack-build-log.txt`, `verification-app-build-log.txt`: the time records and the preflight of each stack.
- `lab-limits-at-end.json`, `lab-fpm-ini-at-end.json`: the lab's limits read through php-fpm.
