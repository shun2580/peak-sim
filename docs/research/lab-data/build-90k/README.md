# Can the lab build the database at 90,000 rows? (the build and the count, then one ANALYZE)

A check made before any prediction at 90,000 rows (the fourth lab backtest, `docs/simulator-spec.md` section 7.4.3): the lab's builder is run once at 90,000 rows with the same recipe as the
10,000 to 300,000-row databases, the rows are counted, and `ANALYZE TABLE` is run once on every table. **No request was sent to the application, no EXPLAIN was run on this database and no
load was applied to it**; the only statements run on its data are the `SELECT COUNT(*)` of `counts.tsv` and the `ANALYZE TABLE` below (the sizes in `size.txt` come from `information_schema`, which reads
the table metadata). The application was left pointing at the 10,000-row database (`bin/use-db` was not run for this scale).

## Result

| | |
| --- | --- |
| Built | yes, exit 0, no failed table beyond the two the same recipe fails at every scale (`role_permissions`, `permission_role`; see `build-db-output.txt`) |
| Command | `poc/lab/bin/build-db --scale=90000 --n-tables=entities,images,references` (the same recipe as the smaller scales) |
| Started / ended | 2026-11-17T08:55:25+09:00 / 2026-11-17T08:56:36+09:00 (`date` before and after the command) |
| The builder's own seconds | migrations 8.63 s, fill 48.56 s, total 59.01 s |
| Size, `information_schema` | 420.7 MiB (data 186.4, index 234.4) |
| Size, on the database volume | 573 MiB for the directory of this database (`du -sm`) |
| Counted | 2026-11-17T08:56:58+09:00 to 2026-11-17T08:57:03+09:00 (`counts.tsv`) |
| Counts | `counts.tsv`: 12 tables at exactly 90,000 rows; `joint_permissions` at 810,000 (9 per entity, as at every scale); `entity_container_data` 54,000 and `entity_page_data` 36,000 (the split of the 90,000 entities into containers and pages, 3:2 as at 200,000 and 300,000) |

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
| Time | 2026-11-17T08:59:39+09:00 to 2026-11-17T08:59:40+09:00 (local time, whole seconds; the run took under a second; `analyze-timing.tsv`) |
| `last_update` before (server clock, UTC) | 2026-11-16 23:55:40 to 2026-11-16 23:57:32 (`analyze-table-stats-before.tsv`; the time of the build) |
| `last_update` after (UTC) | 2026-11-16 23:59:39 to 2026-11-16 23:59:39 (`analyze-table-stats-after.tsv`) |
| Tables whose `n_rows` changed | 9 of 41 |

A one-table `ANALYZE TABLE` of `entities` was also run by hand once on this database, while finding why the first run of the script wrote an empty file (the statements had no semicolon, so none was executed; the empty output is the first run's). The `before` files were read before that; the `after` files, the timing and the baseline are of the full run that followed. The first run of the count script (08:56:58) also wrote an empty `counts.tsv` (its client was started without standard input, so no statement was sent); the file here is from the second run. Nothing else touched the data.

What `n_rows` was in the tables the estimate reads most (before to after; these are the optimizer's estimates, not counts, the counts are in `counts.tsv`): `entities` 87,765 to 84,645; `entity_container_data` 54,210 to 54,480; `images` 89,307 to 89,482; `references` 90,048 to 85,864; `joint_permissions` 810,052 to 789,968.

`dbstats-baseline.tsv` holds, per table of this schema, the read, write, fetch, insert, update and delete counts of the server (`table_io_waits_summary_by_table`) read right after the `ANALYZE`; these counters include the build's own inserts.
A later reading is to be compared with this one.

## What this does not say

That the lab can *answer* at this size: no request was made. The time of a request, the plans the optimizer picks at 90,000 rows in the lab's own database, and whether the buffer pool holds what a request reads were not looked at, so that the count stays unseen
until the prediction is committed.

## Files

- `build-db-output.txt`: the output of the build command, unedited.
- `counts.tsv`: the row counts. `size.txt`: the size of this lab database.
- `analyze-output.txt`, `analyze-timing.tsv`, `analyze-table-stats-before.tsv`, `analyze-table-stats-after.tsv`: the one `ANALYZE TABLE` run.
- `dbstats-baseline.tsv`: the baseline of the server's statement counters of this database.
