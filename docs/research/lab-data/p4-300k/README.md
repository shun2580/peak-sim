# Which statements read the extra rows of two BookStack routes at 300,000 rows? (a cause-finding measurement, not a test)

Made on 2026-11-19 in the lab, on the 300,000-row database that was built on 2026-11-16 (`../build-300k/`). It looks for the cause of two misses
of the third run of test A (`docs/research/extrapolation-test-3-causes.md`, sections 5 and 6). **It is not a test of A or B. No pass or
failure, no condition count and no earlier record is taken from it, and the records and verdicts at 300,000 rows (A and B) are as they were.**
Nothing in the estimate, the calibration, the formulas or the mark rules was changed. The result is in section 10 of that file.

**The database is no longer a 300,000-row record.** The steps below changed it on purpose (the statistics of `references` moved several times,
`ANALYZE TABLE` ran twice, and the last steps emptied `references`). It must not be used as the 300,000-row database of any earlier or later
record. A database of that count is built again by the lab's builder when one is needed.

## What was measured

Two routes, one cold request each at a time, with the measurement's own process (`poc/runtime/bin/measure-one`, run in the lab's tools
container; every request inside a transaction that is rolled back):

| route | URI sent |
| --- | --- |
| `GET /shelves/{slug}` | `/shelves/1` |
| `GET /books/{bookSlug}/page/{pageSlug}` | `/books/1/page/3` |

For each route and each state of the database there are three runs:

1. **The tapped run** (`--tap-out`; warm-up, then cold): for every statement, how many times it ran and how many rows the database read for it
   (the growth of the session's seven `Handler_read_*` counters between the end of the statement before and the end of this one, D96), and the
   `EXPLAIN` of the first and the last values the text was bound to. File: `<route>.tap.json` (the plan is the one of the state the request ran in).
2. **A run with the warm-up only** and 3. **a run with warm-up and cold** (`<route>.w.counters.json`, `<route>.wc.counters.json`): the same
   process without the tap. Around each, the server's counters were read as root: the digest table, the global `Handler_read_*`, and, from
   `performance_schema.events_statements_history_long`, every `statement/com/Execute` event of the process in order, split by transaction
   (`ps_execute_events_by_attempt`: rows examined and rows sent of each execution; the last transaction of the `wc` run is the cold request).

## The states, in the order they were made

| label | how the database got there | `references`: rows (count) / `n_rows` in `mysql.innodb_table_stats` | run (local time) |
| --- | --- | --- | --- |
| `s0-before`, `s0-before-run2` | as built on 2026-11-16 and analyzed once (`../build-300k/`) | 300,000 / 299,088 | 08:38 to 08:48 |
| `regen-rb` | the maintenance route `POST /settings/maintenance/regenerate-references` sent by `measure-one` (rolled back, as test A's repeat sent it): 5,159 statements, 431,434,405 rows, 605 s | 300,000 / 0 (after 70 s) | 08:49:07 to 08:59:12 |
| `s1-after-regen-rb` | the state test A's measuring database was in after that route | 300,000 / 0 | 09:00:39 to 09:04:50 |
| `analyze-1`, `s2-after-analyze-rb` | `ANALYZE TABLE` on the 41 tables (the lab's way, the server's default sample), data unchanged | 300,000 / 299,088 | 09:05:49; 09:05:51 to 09:09:50 |
| `regen-real` | the app's own `ReferenceStore::updateForAll()` run **committed** (the call the route and `artisan bookstack:regenerate-references` make), 299.2 s, exit 0 | **0** / 0 | 09:10:00 to 09:15:00 |
| `s3-after-regen-real` | that state, statistics as the server left them | 0 / 0 | 09:16:12 to 09:19:57 |
| `analyze-2`, `s4-after-regen-real-analyze` | `ANALYZE TABLE` again (the build, rebuild, ANALYZE order of docs/simulator-spec.md 7.4.4) | 0 / 0 | 09:19:57; 09:19:59 to 09:23:41 |

`s0-before` is the first run of the before state: its page route stopped (the tools container's php `memory_limit` is 512M; the request ran out
of memory, `s0-before/failed-512M/`), so the page route was run again with `php -d memory_limit=-1` for the tool's own process (the container's
setting was not changed; test A's measuring image also ran with no memory limit), and every later run used it. `s0-before-run2` is a full second
run of the before state, with the `performance_schema` events added to the driver; the two runs read the same rows (shelf 244, page 1,980,245).
`trial-shelf.tap.json` is a first trial of the tool on the shelf route before the record began (the same 244 rows).

The `regen-real` call was run from `tools/regen_real.php` (booted through the runtime's own `AppBooter`, no transaction around the call). It is not the HTTP route: the route also writes one `activities` row for the log, which this call does not.

## Result (the rows of one cold request, from the tapped run)

The untapped `wc` run of each state read the same total to the row, except the page route in `s1-after-regen-rb`: 2,355,231 against 2,355,235 (4 rows fewer; the untapped run has no per-statement split of its own, so where the 4 rows went is not known; the tapped run is the record of the table below).

| state | shelf: total rows | shelf: `select distinct from_type from references` | shelf: `select count(*) from references where to_type = ? and to_id = ? and (…)` | page: total rows | page: the two statements together |
| --- | ---: | ---: | ---: | ---: | ---: |
| `s0-before-run2` | 244 | 8 | 8 | 1,980,245 | 8 + 9 |
| `s1-after-regen-rb` (statistics 0, data intact) | **375,234** | **300,002** | **75,004** | **2,355,235** | 300,002 + 75,005 |
| `s2-after-analyze-rb` | 244 | 8 | 8 | 1,980,245 | 8 + 9 |
| `s3-after-regen-real` (`references` empty) | 230 | 2 | 0 | 1,980,230 | 2 + 0 |
| `s4-after-regen-real-analyze` | 230 | 2 | 0 | 1,980,230 | 2 + 0 |

The page route's other two large statements read 1,200,000 and 779,999 rows in every state, and every other statement of both routes reads
the same rows in every state (228 rows for the shelf route, 229 for the page route, over 16 and 22 statements). The plan of the two
`references` statements, first binding, `EXPLAIN` of the state:

| statement | statistics in order (s0, s2) | statistics 0 (s1) |
| --- | --- | --- |
| `select distinct from_type from references` | `range` on `references_from_type_index`, "Using index for group-by", 4 rows | `index` on `references_from_type_index`, "Using index", 1 row |
| `select count(*) … from references where to_type = ? and to_id = ? …` | `ref` on `references_to_id_index`, 4 rows | `ref` on `references_to_type_index`, 1 row |

Cross-check with the server's own `performance_schema` (the `wc` run, last transaction): the number of `Execute` events in the cold
transaction is 20 (shelf) and 26 (page), two more than the 18 and 24 statements the app counted, in every state; which two statements those are
is not identified (the `Execute` events carry no SQL text on this server). The two largest executions after the rolled-back rebuild are 300,000 and 75,003 rows for the shelf route (300,000
and 75,004 for the page route); their sum with the rest is 375,172 against the handler figure 375,234. `performance_schema` counts rows examined
differently from the handler counters (for the page route 1,620,174 against 1,980,245 before the rebuild: it gives 959,999 and 659,998 where the handler gives
1,200,000 and 779,999), so the rows of this directory are the handler figures (D96) and the server's figures serve as a second reading of the
size and number of the large statements. The global `Handler_read_*` of the server cannot isolate one request: the measuring process itself reads
the counts of the tables when it starts, which is millions of rows. They are kept in the `*.counters.json` files and not used.

## What was changed on the server, and put back

Nothing in the lab's files or settings was changed (`limits-start.txt` and `limits-end.txt` are the same reading of php, php-fpm and nginx limits: 30 s,
512M, 0s, 120s; read with `poc/lab/bin/check-limits --require=none`). One runtime setting of the database server was changed and put back: the
`performance_schema` consumer `events_statements_history_long`, from NO to YES at 08:43:32 and back to NO at 09:23:52 with the table truncated;
the read-back values before, during and after are in `ps-consumers.txt`. It is a runtime setting (lost at a restart anyway); no file was touched. The
app's own log of each run is the `*.stderr` file beside it (the runtime sends it to standard error); the containers' logs were copied before the
stack was taken down (`container-logs/`, the app's and the database's are small, the web server's and the tools container's are empty). The stack was
taken down with `poc/lab/bin/down` (no `-v`) at 09:24:02; the volume `peak-lab-dbdata` is kept.

## What this does not say

- It was measured on the lab's database (built by the lab's builder for three tables at 300,000 rows), not on the database that test A measured
  (a database built by the measuring tool, with every table at 300,000 rows, not analyzed). The two statements and the way the statistics move
  them are the same in both (the extra rows and the sums agree to the row, `docs/research/extrapolation-test-3-causes.md` section 10). The page route's
  lower level in test A (4.2·N) is **not** seen here (6.6·N in every state).
- That `references` is empty after the rebuild is a fact of this database (its generated pages hold no links the rebuild could find);
  it says nothing about an application with real content.
- The persistent statistics are a sample: `n_rows` of `entities` was 296,185 before, 287,740 after the first `ANALYZE` and 312,719 after the
  last; none of this moved a plan that these two routes read.

## Files

- `tools/p4.py` (the driver: `state`, `regen-rb`, `regen-real`, `analyze`), `tools/p4_report.py` (prints a state's records), `tools/regen_real.php`.
- `<label>/`: `*.tap.json` / `*.tap.stdout` / `*.tap.stderr` (tapped run), `*.w.*` and `*.wc.*` (counters runs), `stats-*.tsv` (the table statistics
  read before and after), `counts-*.tsv` (row counts of five tables), `summary.json`. `regen-rb/`, `regen-real/`, `analyze-1/`, `analyze-2/` hold the
  step's own records. `log-s*.txt` are the driver's output lines.
