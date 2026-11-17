# Can the lab build the database at 150,000 rows? (the build and the count, then one ANALYZE)

A check made before any prediction at 150,000 rows (the fourth lab backtest, `docs/simulator-spec.md` section 7.4.3): the lab's builder is run once at 150,000 rows with the same recipe as the
10,000 to 300,000-row databases, the rows are counted, and `ANALYZE TABLE` is run once on every table. **No request was sent to the application, no EXPLAIN was run on this database and no
load was applied to it**; the only statements run on its data are the `SELECT COUNT(*)` of `counts.tsv` and the `ANALYZE TABLE` below (the sizes in `size.txt` come from `information_schema`, which reads
the table metadata). The application was left pointing at the 10,000-row database (`bin/use-db` was not run for this scale).

## Result

| | |
| --- | --- |
| Built | yes, exit 0, no failed table beyond the two the same recipe fails at every scale (`role_permissions`, `permission_role`; see `build-db-output.txt`) |
| Command | `poc/lab/bin/build-db --scale=150000 --n-tables=entities,images,references` (the same recipe as the smaller scales) |
| Started / ended | 2026-11-17T08:57:08+09:00 / 2026-11-17T08:58:49+09:00 (`date` before and after the command) |
| The builder's own seconds | migrations 6.57 s, fill 80.37 s, total 87.33 s |
| Size, `information_schema` | 669.9 MiB (data 301.5, index 368.4) |
| Size, on the database volume | 849 MiB for the directory of this database (`du -sm`) |
| Counted | 2026-11-17T08:58:55+09:00 to 2026-11-17T08:59:00+09:00 (`counts.tsv`) |
| Counts | `counts.tsv`: 12 tables at exactly 150,000 rows; `joint_permissions` at 1,350,000 (9 per entity, as at every scale); `entity_container_data` 90,000 and `entity_page_data` 60,000 (the split of the 150,000 entities into containers and pages, 3:2 as at 200,000 and 300,000) |

The same 12 tables are at the scale as in the 200,000 and 300,000-row builds (`activities`, `deletions`, `entities`, `entity_permissions`, `favourites`, `images`, `references`, `search_terms`, `slug_history`, `tags`, `views`, `watches`); every other table has the base size (5 rows, or its fixed content).

The database server keeps all scales at once on one volume, so the whole data directory (7,935 MiB at the end of the work for both counts, with the binary logs) is more than this database alone.

## The version of the generator

The last commit that changed any file the builder uses (`poc/runtime/bin/inner/build-db.php`, `poc/runtime/src/Data/`, `poc/runtime/src/Measure/`) is `5fc6796` (2026-11-16 01:20:09); `git status` shows no difference from `HEAD` in those paths
(checked on 2026-11-17 after the build). If the generator is changed later, this database has to be built again, and this record no longer describes the data.

## The statistics were put in order once (ANALYZE TABLE)

After the build and the count, `ANALYZE TABLE` was run once on each of the 41 tables of this database at the server's default sample size (`innodb_stats_persistent_sample_pages` 20; no table option was changed and the automatic recalculation
(`innodb_stats_auto_recalc` 1) was left at its default). Then the statement statistics of the server were read once as a baseline. **From then on no `ANALYZE` is run again on this database.** The only statements were `ANALYZE TABLE` and reads of
`mysql.innodb_table_stats` and of `performance_schema.table_io_waits_summary_by_table`.

| | |
| --- | --- |
| Statements | 41 `ANALYZE TABLE`, all `status OK` (`analyze-output.txt`) |
| Time | 2026-11-17T08:59:40+09:00 to 2026-11-17T08:59:41+09:00 (local time, whole seconds; the run took under a second; `analyze-timing.tsv`) |
| `last_update` before (server clock, UTC) | 2026-11-16 23:57:22 to 2026-11-16 23:59:08 (`analyze-table-stats-before.tsv`; the time of the build) |
| `last_update` after (UTC) | 2026-11-16 23:59:40 to 2026-11-16 23:59:40 (`analyze-table-stats-after.tsv`) |
| Tables whose `n_rows` changed | 10 of 41 |

What `n_rows` was in the tables the estimate reads most (before to after; these are the optimizer's estimates, not counts, the counts are in `counts.tsv`): `entities` 148,968 to 148,612; `entity_container_data` 5 to 88,522; `images` 149,058 to 148,821; `references` 149,812 to 146,038; `joint_permissions` 150,070 to 1,360,269.

`dbstats-baseline.tsv` holds, per table of this schema, the read, write, fetch, insert, update and delete counts of the server (`table_io_waits_summary_by_table`) read right after the `ANALYZE`; these counters include the build's own inserts.
A later reading is to be compared with this one.

## What this does not say

That the lab can *answer* at this size: no request was made. The time of a request, the plans the optimizer picks at 150,000 rows in the lab's own database, and whether the buffer pool holds what a request reads were not looked at, so that the count stays unseen
until the prediction is committed.

## Files

- `build-db-output.txt`: the output of the build command, unedited.
- `counts.tsv`: the row counts. `size.txt`: the size of this lab database.
- `analyze-output.txt`, `analyze-timing.tsv`, `analyze-table-stats-before.tsv`, `analyze-table-stats-after.tsv`: the one `ANALYZE TABLE` run.
- `dbstats-baseline.tsv`: the baseline of the server's statement counters of this database.
