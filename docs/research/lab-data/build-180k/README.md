# The lab database at 180,000 rows (the build and the count, then one ANALYZE)

The database the load steps of the sixth lab backtest (`docs/simulator-spec.md` sections 7.4.4 and 7.4.5) will be measured on. It is built before any prediction at this size, with the same recipe as the 10,000 to
300,000-row databases: the lab's builder is run once, the rows are counted, and `ANALYZE TABLE` is run once on every table. **The application's own rebuild of the `references` table
(`regenerate-references`) was not run on it**, so `references` holds exactly the generated rows. **No request was sent to the application, no EXPLAIN was run on this database and no load was applied to it**; the only statements
run on its data are the `SELECT COUNT(*)` of `counts.tsv` and the `ANALYZE TABLE` below (the sizes in `size.txt` come from `information_schema`, which reads the table metadata). The application was left pointing at the 30,000-row database
(`bin/use-db` was not run for this scale). The lab tool that built it is a throw-away copy of the tool's tree in which the names of the lab's containers, volume and state directory were replaced by new ones (see the record of the run); the files of the tool were not changed otherwise.

## Result

| | |
| --- | --- |
| Built | yes, exit 0, no failed table beyond the two the same recipe fails at every scale (`role_permissions`, `permission_role`; see `build-db-output.txt`) |
| Command | `poc/lab/bin/build-db --scale=180000 --n-tables=entities,images,references` (the same recipe as the smaller scales) |
| Started / ended | 2026-11-21T20:16:20+09:00 / 2026-11-21T20:18:37+09:00 (`date` before and after the command: 137 s) |
| The builder's own seconds | migrations 8.96 s, fill 114.47 s, total 124 s |
| Size, `information_schema`, after the ANALYZE | 788.1 MiB (data 357.6, index 430.5) |
| Size, `information_schema`, before the ANALYZE | 543.1 MiB (data 272.9, index 270.2). `information_schema` answers from the statistics the server holds, so the same database reads 543.1 MiB before and 788.1 MiB after one `ANALYZE TABLE`; a size is comparable only with one read at the same point (`first-attempt-no-analyze/size.txt` is the reading before). |
| Size, on the database volume | 981 MiB for the directory of this database (`du -sm`); the whole data directory of the database server was 1,555 MiB (this is the only database on this volume besides the server's own) |
| Counted | 2026-11-21T20:18:37+09:00 to 2026-11-21T20:18:43+09:00 (`counts.tsv`) |
| Counts | `counts.tsv`: 12 tables at exactly 180,000 rows; `joint_permissions` at 1,620,000 (9 per entity, as at every scale); `entity_container_data` 108,000 and `entity_page_data` 72,000 (the split of the 180,000 entities into containers and pages, 3:2 as at the other scales) |

`references` is at 180,000, the generated count: it was not emptied or refilled by a rebuild.
The same 12 tables are at the scale as in the 150,000, 170,000, 200,000 and 300,000-row builds (`activities`, `deletions`, `entities`, `entity_permissions`, `favourites`, `images`, `references`, `search_terms`, `slug_history`, `tags`, `views`, `watches`); every other table has the base size (5 rows, or its fixed content).

## The version of the generator

The last commit that changed any file the builder uses (`poc/runtime/bin/inner/build-db.php`, `poc/runtime/src/Data/`, `poc/runtime/src/Measure/`) is recorded in `generator-version.txt` together with the commit the tool was taken from (`20a127a`); `git diff` between them over those paths is empty.
If the generator is changed later, this database has to be built again, and this record no longer describes the data.

## The statistics were put in order once (ANALYZE TABLE)

After the build and the count, `ANALYZE TABLE` was run once on each of the 41 tables of this database at the server's default sample size (`innodb_stats_persistent_sample_pages` 20; no table option was changed and the automatic recalculation
(`innodb_stats_auto_recalc` 1) was left at its default). Then the statement statistics of the server were read once as a baseline. **From then on no `ANALYZE` is run again on this database.** The only statements were `ANALYZE TABLE` and reads of
`mysql.innodb_table_stats` and of `performance_schema.table_io_waits_summary_by_table`.

**A first attempt did not run.** The first script sent the 41 statements to the server without keeping its input open, so none reached it: the output was empty, `last_update` of every table stayed at the time of the build, and 0 `ANALYZE TABLE` statements ran. This was seen at once from the empty output and the unchanged `last_update`; the readings of that attempt are kept in `first-attempt-no-analyze/` (with a note), and the statements were then sent once, correctly. So the database was analyzed exactly once.

| | |
| --- | --- |
| Statements | 41 `ANALYZE TABLE`, all `status OK` (`analyze-output.txt`; the file also holds the 41 header lines of the server's replies) |
| Time | 2026-11-21 20:19:09 (local time, whole seconds; the run took less than 1 s; `analyze-timing.tsv`) |
| `last_update` before (server clock, UTC) | 2026-11-21 11:16:34 to 11:19:06 (`analyze-table-stats-before.tsv`; the time of the build) |
| `last_update` after (UTC) | 2026-11-21 11:19:09 to 11:19:09 (`analyze-table-stats-after.tsv`) |
| Tables whose `n_rows` changed | 5 of 41 |

What `n_rows` was in the tables the estimate reads most (before to after; these are the optimizer's estimates, not counts, the counts are in `counts.tsv`): `entities` 180,922 to 168,506; `entity_container_data` 5 to 107,108;
`images` 179,051 to 178,695; `references` 179,560 to 179,560; `joint_permissions` 1,634,223 to 1,635,384.

`dbstats-baseline.tsv` holds, per table of this schema, the read, write, fetch, insert, update and delete counts of the server (`table_io_waits_summary_by_table`) read right after the `ANALYZE`; these counters include the build's own inserts.
A later reading is to be compared with this one.

## What this does not say

That the lab can *answer* at this size: no request was made. The time of a request, the plans the optimizer picks at 180,000 rows in the lab's own database, and whether the buffer pool holds what a request reads were not looked at, so that the count stays unseen
until the prediction is committed. The prediction's inputs (statistics reads, EXPLAIN) are taken elsewhere, on a throw-away database of each person's own stack, as in the dry run.

## Files

- `build-db-output.txt`: the output of the build command, unedited except that the run id inside the database name is written `<runid>`.
- `counts.tsv`: the row counts. `size.txt`: the size of this lab database (read after the ANALYZE).
- `analyze-output.txt`, `analyze-timing.tsv`, `analyze-table-stats-before.tsv`, `analyze-table-stats-after.tsv`: the one `ANALYZE TABLE` run.
- `dbstats-baseline.tsv`: the baseline of the server's statement counters of this database.
- `databases-after-build.txt`: the databases of the server after the build (this one only, besides the server's own), run id written `<runid>`.
- `first-attempt-no-analyze/`: the readings of the attempt in which no statement reached the server.
- `generator-version.txt`: the commit of the tool and the last commit that changed the builder's files.
