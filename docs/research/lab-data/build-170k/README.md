# The lab database at 170,000 rows (the build and the count, then one ANALYZE)

The database the load steps of the fifth lab backtest (`docs/simulator-spec.md` section 7.4.4) will be measured on. It is built before any prediction at this size, with the same recipe as the 10,000 to
300,000-row databases: the lab's builder is run once, the rows are counted, and `ANALYZE TABLE` is run once on every table. **The application's own rebuild of the `references` table
(`regenerate-references`) was not run on it**, so `references` holds exactly the generated rows. **No request was sent to the application, no EXPLAIN was run on this database and no load was applied to it**; the only statements
run on its data are the `SELECT COUNT(*)` of `counts.tsv` and the `ANALYZE TABLE` below (the sizes in `size.txt` come from `information_schema`, which reads the table metadata). The application was left pointing at the 30,000-row database
(`bin/use-db` was not run for this scale; `PEAK_LAB_SCALE` read from the running app container is 30000 after the work), and no lab setting was changed (`git status` of `poc/lab` is empty).

## Result

| | |
| --- | --- |
| Built | yes, exit 0, no failed table beyond the two the same recipe fails at every scale (`role_permissions`, `permission_role`; see `build-db-output.txt`) |
| Command | `poc/lab/bin/build-db --scale=170000 --n-tables=entities,images,references` (the same recipe as the smaller scales) |
| Started / ended | 2026-11-19T17:18:49+09:00 / 2026-11-19T17:21:02+09:00 (`date` before and after the command: 133 s) |
| The builder's own seconds | migrations 7.24 s, fill 112.31 s, total 119.96 s |
| Size, `information_schema` | 746.0 MiB (data 336.1, index 409.9) |
| Size, on the database volume | 949 MiB for the directory of this database (`du -sm`); the whole data directory of the database server, all scales together, was 9,209 MiB at the end of the work |
| Counted | 2026-11-19T17:21:10+09:00 to 2026-11-19T17:21:16+09:00 (`counts.tsv`) |
| Counts | `counts.tsv`: 12 tables at exactly 170,000 rows; `joint_permissions` at 1,530,000 (9 per entity, as at every scale); `entity_container_data` 102,000 and `entity_page_data` 68,000 (the split of the 170,000 entities into containers and pages, 3:2 as at the other scales) |

`references` is at 170,000, the generated count: it was not emptied or refilled by a rebuild (the generated bodies hold no links to each other, so a rebuild would leave it far below the count).
The same 12 tables are at the scale as in the 150,000, 200,000 and 300,000-row builds (`activities`, `deletions`, `entities`, `entity_permissions`, `favourites`, `images`, `references`, `search_terms`, `slug_history`, `tags`, `views`, `watches`); every other table has the base size (5 rows, or its fixed content).

## The version of the generator

The last commit that changed any file the builder uses (`poc/runtime/bin/inner/build-db.php`, `poc/runtime/src/Data/`, `poc/runtime/src/Measure/`) is `32d3640` (2026-11-19 12:20:31); `git status` shows no difference from `HEAD` in those paths and in `poc/lab`
(checked on 2026-11-19 after the build; `HEAD` was `07efce2`). If the generator is changed later, this database has to be built again, and this record no longer describes the data.

## The statistics were put in order once (ANALYZE TABLE)

After the build and the count, `ANALYZE TABLE` was run once on each of the 41 tables of this database at the server's default sample size (`innodb_stats_persistent_sample_pages` 20; no table option was changed and the automatic recalculation
(`innodb_stats_auto_recalc` 1) was left at its default). Then the statement statistics of the server were read once as a baseline. **From then on no `ANALYZE` is run again on this database.** The only statements were `ANALYZE TABLE` and reads of
`mysql.innodb_table_stats` and of `performance_schema.table_io_waits_summary_by_table`.

| | |
| --- | --- |
| Statements | 41 `ANALYZE TABLE`, all `status OK` (`analyze-output.txt`; the file also holds the 41 header lines of the server's replies) |
| Time | 2026-11-19T17:21:26+09:00 to 2026-11-19T17:21:27+09:00 (local time, whole seconds; the run took 1 s; `analyze-timing.tsv`) |
| `last_update` before (server clock, UTC) | 2026-11-19 08:19:02 to 08:21:20 (`analyze-table-stats-before.tsv`; the time of the build) |
| `last_update` after (UTC) | 2026-11-19 08:21:27 to 08:21:27 (`analyze-table-stats-after.tsv`) |
| Tables whose `n_rows` changed | 9 of 41 |

What `n_rows` was in the tables the estimate reads most (before to after; these are the optimizer's estimates, not counts, the counts are in `counts.tsv`): `entities` 179,760 to 171,392; `entity_container_data` 5 to 101,892;
`images` 168,806 to 168,806; `references` 169,644 to 169,644; `joint_permissions` 1,532,717 to 1,532,058.

`dbstats-baseline.tsv` holds, per table of this schema, the read, write, fetch, insert, update and delete counts of the server (`table_io_waits_summary_by_table`) read right after the `ANALYZE`; these counters include the build's own inserts.
A later reading is to be compared with this one.

## What this does not say

That the lab can *answer* at this size: no request was made. The time of a request, the plans the optimizer picks at 170,000 rows in the lab's own database, and whether the buffer pool holds what a request reads were not looked at, so that the count stays unseen
until the prediction is committed. The prediction's inputs (statistics reads, EXPLAIN) are taken elsewhere, on a throwaway database of each person's own stack, as in the dry run.

## Files

- `build-db-output.txt`: the output of the build command, unedited.
- `counts.tsv`: the row counts. `size.txt`: the size of this lab database.
- `analyze-output.txt`, `analyze-timing.tsv`, `analyze-table-stats-before.tsv`, `analyze-table-stats-after.tsv`: the one `ANALYZE TABLE` run.
- `dbstats-baseline.tsv`: the baseline of the server's statement counters of this database.
