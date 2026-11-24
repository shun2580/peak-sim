This is a dry run, not a test.

# Dry run of test A on Bagisto at 30,000 rows (the real round is at 240,000 rows)

**Not a test, not a reference value, not used for a pass or a failure.** The count of 30,000 rows was seen before (the capture of the app reached it). Nothing here is carried into a prediction, a rule, a pass line or a verdict of the real round. A number in these records is never looked at as a result; what was checked is whether the whole chain runs from the start to the end with the real tools: the estimate side (EXPLAIN at two sizes, the estimate, the list of marked routes), the order of a prediction commit before the first measurement, the measurement of every route cold once, the repeat of some routes, the memory cap on the measured app, and the scripts that read the records and write the verdict. Times are the host clock (JST, UTC+9) on 2026-11-24. In the text files the home directory of the working machine is written `~`, the user name `<user>` and the host name `<host>`.

No database of 240,000 rows was made, no estimate or prediction of the real round was made or committed, nothing was measured at 240,000 rows.

## What the dry run used

| What | Value |
| --- | --- |
| The tool | a throw-away clone of the repository (no remote, never pushed). Final pass: `3018d4a6a3bd68918c6d4e047f1cd619178ef211` = the repository's `a25d310` (the Bagisto profile's `state_changing` list, 5 routes) plus one commit that holds only the two rehearsal files `poc/lab/predictions/dryrun24-bagisto-{estimate,marked}.json` (committed in the clone only; the real repository's prediction folder was not touched). The gate printed `PREDICTION OK` for both files, twice per step (`measure/measure.stdout.log.txt`, `variance/repeat.stdout.log.txt`) |
| The app | the Bagisto tree used by the capture, image `peak-measure-php-bagisto:8.4` (already built, nothing pulled or built), data by the generator with the shared plan of the 123 tables of `docs/research/bagisto-prep/n-tables.txt` (`--n-tables`) |
| The door | `poc/lab/bin/measure-extrapolation ... measure` and `... repeat`, `--scales=30000`, `--warm=off`, `--request-timeout=300`, `--keep-stack` (the real round's door and options, except the route list and the timeout; see below) |
| Compose projects (all new; no volume of an earlier project was touched) | `peak-bagisto-dry24-eas` (EXPLAIN), `peak-bagisto-dry24` and `peak-bgdry24-var-dry24-db1` (first pass), `peak-bagisto-dry24c` (memory-cap test), `peak-bagisto-dry24f` and `peak-bgdry24f-var-dry24-db1` (final pass) |
| Before anything was made (`preflight.txt`) | 15,936 MB memory with 11,882 MB available; `/` 1,007 GB with 885 GB free; 41 volumes (sorted-name sha256 `a30cfee6...2fa0`), no compose project named `dry24`; the host's update pause was read from the registry (paused until 2026-11-28 15:02 UTC, active hours 0 to 18) and the last planned-restart event (id 1074) was 2026-11-23 02:10; read again at 12:01 with the same result |
| After the last stop | 47 volumes: the 41 earlier ones (the sorted-name sha256 of the names without `dry24` in them is the same) and 6 new ones, one per project above. None was removed. No container is left running |

## What was done, in order

| Time | What |
| --- | --- |
| 11:37:20 to 11:37:22 | the fit and the rehearsal prediction at 30,000 rows from the capture's records (`estimate/fits.json`, `estimate/predictions-30000.json`) |
| 11:37:37 to 11:43:32 (5 min 55 s) | EXPLAIN at 30,000 rows and at 3,000 rows on throw-away databases (`explain/`): 63 routes, 432 statement types, 192 sent as EXPLAIN, three table-statistics states (default, 4 and 200 sample pages); 1 plan_unstable, 31 plan_changes_at_scale, 0 explain_unavailable. The 30,000-row database was built in 278 s, the 3,000-row one in 33 s |
| 11:43:56 and 11:43:58 | the estimate and the marked list were made twice; both runs are byte for byte the same (`estimate/two-runs.sha256`). 57 routes have a complete estimate; 19 routes are marked (plan_changes_at_scale); the offset mark (`offset_chunk_fit`) marks 0 of the 6 routes with an offset statement |
| 11:44:17 to 11:58:28 | **first pass**: rehearsal files committed in the clone, then measure (11 routes, 7 min) and repeat (7 min). This pass ran before the `state_changing` list was added and with no memory cap. Only the reports are kept (`first-pass/`) |
| 11:52 | the clone was moved onto `a25d310` and the two rehearsal files were committed again on top of it (`3018d4a`, committed 11:52:28) |
| 12:00:22 to 12:05:32 | **memory-cap test** with a cap of 400 MB (`memory-cap/`, below) |
| 12:06:47 to 12:15:08 (8 min 21 s) | **final pass, measure**: 11 routes cold once, at 30,000 rows, with the 6 GB cap. Database build 136.96 s (the empty probe database 40.14 s); the routes 134.72 s at 30,000 rows (3.39 s at the empty probe) |
| 12:15:08 to 12:21:33 (6 min 25 s) | **final pass, repeat**: 6 routes, 2 laps, one database (`variance/`). Database build 140 s |
| 12:22:42 | the containers were stopped: the app containers with `docker stop -t 30`, the databases with `docker stop -t 180`. Each database log ends with `Received SHUTDOWN ... Shutdown complete`, exit code 0 (a clean shutdown; no crash recovery is due at the next start) |
| 12:23 | the two databases of the final pass were started once to read the table sizes, then stopped again the same way (exit 0) |

## The routes, and why these

11 of the 120 routes of the list, chosen to cover the cases the real round meets, not for their numbers (`measure/tasks.json` holds each request's arguments).

| Route | Why |
| --- | --- |
| `GET /customer/account`, `GET /customer/account/wishlist` | the customer-side door: the tool logs in as the measurement customer first |
| `POST /customer/account/orders/cancel/{id}`, `POST /customer/account/profile/destroy` | customer side, and both change tables other routes read (in the profile's `state_changing` list) |
| `GET /admin/customers/search`, `GET /admin/customers/view/{id}` | the admin-side door (`view/{id}` is the route whose PHP memory is expected to be the largest) |
| `POST /admin/sales/invoices/create/{order_id}`, `DELETE /admin/sales/rma/rules/delete/{id}` | admin side, a POST and a DELETE |
| `PUT /api/checkout/cart` | a PUT with a body (the first attempts answered 422 until the tool filled `qty`; it took 5 attempts) |
| `GET /search`, `GET /api/products` | public routes, no login |

Five of the 11 are in the list of 19 routes whose data seems to have arrived (`GET /api/products`, `GET /admin/customers/search`, `GET /admin/customers/view/{id}`, `POST /customer/account/orders/cancel/{id}`, `POST /admin/sales/invoices/create/{order_id}`). None of the 11 is among the 19 marked routes of the rehearsal estimate, so the six routes of the repeat (`GET /api/products`, `GET /customer/account`, `GET /customer/account/wishlist`, `GET /admin/customers/view/{id}`, `POST /customer/account/orders/cancel/{id}`, `POST /admin/sales/invoices/create/{order_id}`) were taken as stand-ins to exercise the repeat's ordering and its table statistics step; in the real round the repeat takes the marked routes.

## What was checked

1. **The doors work.** All 11 routes were tried at 30,000 rows: 26 attempts, 22 `ok` and 4 `validation_422` (the first attempts of `PUT /api/checkout/cart` and of `POST /customer/account/profile/destroy`). The customer-side routes and the admin-side routes answered after the login. The statement `act-as: no Customer with key 1` seen at the empty probe database (scale 0) is the probe's: the empty database has no customer. `report.md` and `routes.jsonl` take each route's `class` from that probe (the summary shows `exception 4, not_found 2, ok 1, unfilled_param 4`), not from the 30,000-row attempts in `attempts.jsonl`; the earlier dry run's records have the same shape.
2. **Routes that change tables are measured after the others.** In the cold-once measure (`measure/attempts.jsonl`, in the order of the attempts) `POST /customer/account/orders/cancel/{id}` and then `POST /customer/account/profile/destroy` come last, after `GET /search`, although the route list gives them earlier places. In the repeat (`variance/dry24/db1/index.tsv`) `POST /customer/account/orders/cancel/{id}` is the last route of each lap, and `run.log.txt` shows an `ANALYZE TABLE` on `order_items` and `product_inventory_indices` (the tables the route rewrites, from the profile) after lap 1 and after lap 2, outside any request. The first pass, before the list existed, ran the same route fifth of six. `DELETE /admin/sales/rma/rules/delete/{id}`, `POST /admin/sales/invoices/create/{order_id}` and `PUT /api/checkout/cart` also write tables, are not in the profile's list, and were measured in their place in the route list. That is the list as committed; nothing was changed.
3. **The order of the prediction and the measurement.** `order_record.txt` and the gate lines: the rehearsal files were committed in the clone (11:44:17 first pass, 11:52:28 final pass) before the first request of each pass (11:44:23 and 12:06:47), and the files are the same bytes in both commits.
4. **The databases were built from the shared plan** (`--n-tables`), in one database shared by the routes of the group. `variance/dry24/db1/g1/content.tsv` has the row count of every table; `sizes/table-sizes-30000.tsv` has the sizes (below).
5. **The verdict script runs to the end** (`judge/a-verdict.md`): `judge_a.py` (unchanged, with the comparison tool pinned by its sha256) read the rehearsal estimate and marked files, the measure and the repeat, and wrote its table. The verdict is **undecidable**, as it must be with 11 routes of the 57 that have an estimate (46 not measured, more than 20 %).
6. **The count on the 19 listed routes runs to the end** (below).
7. **The memory cap works** (below).
8. **Teardown.** Nothing was removed (`docker volume ls`: 41 earlier volumes unchanged, 6 new). The tool's own teardown (`bin/stack down` without `--purge`) keeps the database volume; `--keep-stack` was used anyway and the stacks were stopped by the scripts in `scripts/`.

## The memory cap on the measured app (6 GB, the app container only)

One ruling of the real round caps the memory of the measured app's container at 6 GB (the database gets no cap), because `GET /admin/customers/view/{id}` is expected to need about 7.7 GB of PHP memory at 240,000 rows. A route killed for memory is to be counted as not measured.

**How it is attached, without changing the tool.** `bin/_stack.sh` reads the compose file from `PEAK_COMPOSE_FILE` (default `poc/runtime/compose/measure.compose.yaml`). The dry run set it to a copy of the file with two lines added to the `measure-php` service (`memory-cap/compose.diff`, whole file `memory-cap/measure.compose.cap.yaml`, sha256 `9672e093...867c`): `mem_limit` and `memswap_limit`, both `${PEAK_MEASURE_PHP_MEM:-6g}` (equal, so there is no swap). The second difference is the absolute path of the build context, which the copy needs because it lives outside `poc/runtime/compose/` and which is not used when `PEAK_PHP_IMAGE` is set. The `measure` and `repeat` doors pass the variable on to every stack they bring up (the repeat's own stack included). The tool's files are not changed.

**What was seen with a 400 MB cap** (`PEAK_MEASURE_PHP_MEM=400m`, two routes, 30,000 rows; `memory-cap/`):
- `GET /admin/customers/search` (about 6 MB of PHP memory) ran `ok`, both attempts.
- `GET /admin/customers/view/{id}` (922 MB at 30,000 rows in the final pass) ended **`runner_failed`, `exit 9`** (SIGKILL), status none; its row in `routes.jsonl` is `not_measured`. The detail text is a piece of the runner's standard output, not an error message; only attempt number 2 has a record (there is none for attempt 1; why is not known).
- `judge_a.py` on this record counts the route under "Not measured, with an estimate" (`memory-cap/` holds the record; the script's output was not kept).
- The app container stayed up (`State.Status` running, restart count 0) and the database stayed `running healthy`, `oomkilled=false`, restart count 0, no limit (`memory-cap/db_inspect_final.txt`, `test-400m-sampler.log.txt`).

**How to tell that a route was killed for memory.** Three readings agree (a sampler every 3 s wrote them): the cgroup counter `oom_kill` in `/sys/fs/cgroup/memory.events` of the app container went from 0 to 1 (`max 63`, `oom 1`, `oom_kill 1`: `memory-cap/memory_events_final.txt`); the route's class in the attempt record is `runner_failed` with `exit 9`; and `docker inspect` of the container shows `State.OOMKilled=true` **while the container is still running** (the flag stays true for the container's life, so it cannot say which route; the counter's change in time and the route's order can). `docker inspect` `HostConfig.Memory` and `MemorySwap` show the cap in force. The sampler line format is in `scripts/run_final.sh`.

**The final pass ran with the real value (6 GB).** `memory-cap/final-run-sampler.log.txt` (every 5 s, both stacks, 223 lines of the app container): `limit=6442450944 swap=6442450944` in every line, `oom_kill 0` in every line, the largest `memory.current` read 1.09 GB (the sampler can miss a peak between two readings), the database containers `running healthy`, restart count 0, no limit, in every line (two lines at start `starting`).

The cap is on the container from its start, so it also covers the database build, which runs in that container; at 30,000 rows the build ran under the 400 MB cap and under 6 GB without being killed.

## The count on the 19 listed routes

`judge/count-listed.md` (`count_listed.py` and the list of 19 routes were written for the real round; the comparison tool and `judge_a.py` are not changed: the script makes copies of the estimate and the marked list narrowed to the listed routes and runs `judge_a.py` on them). On this dry run: all 19 listed routes have a complete estimate in the rehearsal estimate; 5 were measured, 14 not (the dry run measured 11 routes only); the verdict on the listed routes is **undecidable** and the line is **not met**, from "14 of 19 routes with an estimate could not be measured, more than 20 %" (the denominator, 5, is not under 5). A listed route that is not in the estimate at all would be reported as missing and not counted. The numbers are the dry run's and mean nothing.

## Sizes read at 30,000 rows (`sizes/`)

`information_schema` on the 30,000-row database of the repeat: 152 tables, data 0.590 GB, index 0.237 GB, total 0.827 GB (data_free 0.227 GB); the largest table is `orders` with 28.6 MB (28,419 rows by the estimate of the statistics), then `product_attribute_values` (28.6 MB, 178,617 rows) and `agent_conversation_messages` (21.2 MB). The volume of that stack is 2.73 GB on disk (`du` of the data directory, redo, undo, binary logs and the other databases' leftovers included); the volume of the stack of the cold-once measure holds no database after its run (the tool drops it) and is 1.48 GB. A size at 240,000 rows is not measured by this. A straight line from 0.827 GB would be about 6.6 GB of data and index at 240,000 rows; the earlier build record's per-row size (file sizes of the volumes) is another basis and gives more.

## Length of the real round, from this dry run (conditional)

Only what is measured here is used; every line says what it assumes.

| Part | At 30,000 rows here | At 240,000 rows if the time grows in proportion to the rows (8 times) | Note |
| --- | --- | --- | --- |
| EXPLAIN side (build of the large database, three statistics states, EXPLAIN) | 278 s build, 5 min 55 s in all | about 37 min for the build, about 50 min in all | the states and the EXPLAIN may grow less or more than the build |
| Build of the database of the cold-once measure | 136.96 s | about 18 min | the earlier build estimate puts the middle at 16 min and the pessimistic end at 56 min; the tool stops a build at 7,200 s |
| Build of the database of the repeat | 140 s | about 19 min | one database, built again |
| 11 routes cold once | 134.72 s (26 attempts) | about 18 min | the dry run's routes are not a sample of the list |
| The capture's record of the list at 30,000 rows (112 routes, about 2 attempts each) | 2,338 s (10,000 rows: 2,219 s; 1,000 rows: 1,234 s) | from about 42 min (the time stays as at 30,000: the capture's own three last sizes grew by 5 % over a tripling of the rows) up to about 5.6 h (in proportion to the rows) for 120 routes | the real list is 120 routes; the capture's 112 is the count the capture reached, so the 120 is scaled by 120/112 |
| Repeat laps | 6 routes x 2 laps in 385 s including the 140 s build; about 100 s per lap | three cold laps of the marked routes (19 marked at 30,000 rows here; the real count comes with the real estimate) after the build; per lap between about 100 s x 19/6 = 5 min and 8 times that | marked routes are in general not the cheap ones; a route with a 2-attempt cost of 28 to 53 s at 30,000 rows (`view/{id}`, `/api/products`) would be 4 to 7 min a try in proportion |
| Between laps | an `ANALYZE TABLE` on the tables a state-changing route rewrites (its time is not recorded separately) | seconds to a minute | |

So, for the measuring steps of the real round (build, 120 routes cold once, the repeat's build and three laps), **from about 1.5 hours (flat growth) to about 8 hours (in proportion to the rows)**, and a stop and restart, a route that runs into the request limit, or a build that is slower than in proportion (the database is far bigger than the buffer pool) are not in it. `GET /admin/customers/view/{id}` is expected to be killed by the 6 GB cap (its 922 MB at 30,000 rows is about 7.7 GB at 240,000 rows if in proportion), and is then `not measured`.

## Observed, not changed

- The first try of the repeat stopped at once with exit 2: `--project-prefix` must match `^[a-z0-9][a-z0-9_-]{0,20}$` (`bin/variance-run` line 163), so at most 21 characters, and the name used (`peak-bagisto-dry24-var`, 22 characters) was one too long; the usage message names only the character set, not the length. The prefix was shortened (`peak-bgdry24-var`, 16 characters) and the repeat was started again (`first-pass/` and `variance/repeat.*`).
- `class` in `report.md`/`routes.jsonl` comes from the empty probe (above).
- The data directory logs `Threads are unable to reserve space in redo log` warnings during the builds, as in the earlier builds.

## What was not done

- No database of 240,000 rows, no real estimate, no real prediction commit, no measurement at 240,000 rows.
- The 6 GB cap was never reached at this size (the largest read was 1.09 GB), so what a kill at 6 GB looks like was seen only through the 400 MB test.
- The routes of the repeat were not the marked routes of the real round.

## Files

- `order_record.txt` (start and end of each step, the commits, in order), `preflight.txt`.
- `explain/` (the run's record, the plan, the checksums of its two inputs), `estimate/` (the rehearsal files, the fits, and `two-runs.sha256`).
- `measure/` and `variance/`: the final pass (`report.md`, `routes.jsonl`, `attempts.jsonl`, `summary.json`, `tasks.json`, the logs with the gate lines first, `host_before_*.txt` with `free -m`; the repeat's `db1/`). The 10 MB static analysis the tool copied next to the record is not kept; it is the same file as the capture's.
- `memory-cap/`: the compose copy and its diff, the 400 MB test's record and sampler, the final pass's sampler, the inspect readings.
- `judge/`: `a-verdict.md/json` (`judge_a.py` on the final pass) and `count-listed.*` (the count on the 19 listed routes).
- `sizes/`: table sizes and volume sizes read at 30,000 rows.
- `first-pass/`: the reports of the first pass (before the state-changing list and the cap).
- Every `*.log` of the run is kept with `.txt` added to its name (`*.log` files are not kept in git); `*.log.txt` is the same file.
- `scripts/`: the scripts that ran the steps (the notice lines were taken out and the working paths shown with `~`; the `measure` and `repeat` calls, their options and their order are as run).
