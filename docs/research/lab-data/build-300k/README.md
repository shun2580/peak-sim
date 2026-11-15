# Can the lab build the database at 300,000 rows? (the build and the count, nothing else)

A check made before any prediction at 300,000 rows: the lab's builder is run once at 300,000 rows with the same recipe as the 10,000 to
200,000-row databases, and the rows are counted. **No request was sent to the application, no EXPLAIN was run and no
load was applied to this database**; the only statements run on its data are the `SELECT COUNT(*)` of `counts.tsv` (the sizes in
`size.txt` come from `information_schema`, which reads the table metadata). The application was left pointing at the 10,000-row
database (`bin/use-db` was not run for this scale).

## Result

| | |
| --- | --- |
| Built | yes, exit 0, no failed table beyond the two the same recipe fails at every scale (`role_permissions`, `permission_role`; see `build-db-output.txt`) |
| Command | `poc/lab/bin/build-db --scale=300000 --n-tables=entities,images,references` (the same recipe as the smaller scales) |
| Started / ended | 2026-11-16 01:04:05 / 01:07:43 (3 min 38 s wall clock, `date` before and after the command) |
| The builder's own seconds | migrations 10.58 s, fill 190 s, total 205.26 s |
| Size, `information_schema` | 1,250.9 MiB (data 574.3, index 676.6) |
| Size, on the database volume | 1,552 MiB for the directory of this database (`du -sm`) |
| Counts | `counts.tsv`: 12 tables at exactly 300,000 rows; `joint_permissions` at 2,700,000 (9 per entity, as at every scale); `entity_container_data` 180,000 and `entity_page_data` 120,000 (the split of the 300,000 entities into containers and pages, 3:2 as at 200,000) |

The same 12 tables are at the scale as in the 200,000-row build (`activities`, `deletions`, `entities`, `entity_permissions`, `favourites`,
`images`, `references`, `search_terms`, `slug_history`, `tags`, `views`, `watches`); every other table has the base size (5 rows, or its
fixed content).

For comparison, the builder's total at 200,000 rows was 206.13 s (migrations 45.16 s, fill 125.94 s). One and a half times the rows took
the same total here (205.26 s); the fill alone took 1.5 times as long (190 s against 125.94 s), and the migrations took 10.58 s instead
of 45 s (the 45 s at 200,000 rows was read at the time as the first start of the container runtime after a stop, not as an effect of the
scale). The whole 300,000-row build is under four minutes.

`size.txt` also lists the other lab databases; the lab's database server keeps all scales at once on one volume, so the whole data
directory (6,166 MiB, with the binary logs) is more than this database alone.

## The version of the generator

The last commit that changed any file the builder uses (`poc/runtime/bin/inner/build-db.php`, `poc/runtime/src/Data/`,
`poc/runtime/src/Measure/`) is `c3b8653` (2026-11-11 14:22); `git status` shows no difference from `HEAD` in those paths (checked at 01:19, after the
build; no commit touches them between 11-11 and the build; other paths of the working tree have uncommitted changes of other work). The builder is the same as for the 200,000-row database
except for that commit (a change of the capture record, which the builder does not read). If the generator is changed later, this
database has to be built again, and this record no longer describes the data.

## The statistics were put in order once (ANALYZE TABLE), and the baseline of the server's statement counters was read

After the build and the count, `ANALYZE TABLE` was run once on each of the 41 tables of this database, at the server's default sample
size (the same way as `analyze-lab/`; no table option was changed and the automatic recalculation was left at its default). Then the
statement statistics of the server were read once as a baseline. **From then on no `ANALYZE` is run again on this database.** No request,
no EXPLAIN and no load was applied; the only statements were `ANALYZE TABLE` and reads of `mysql.innodb_table_stats` and of
`performance_schema.table_io_waits_summary_by_table`.

| | |
| --- | --- |
| Statements | 41 `ANALYZE TABLE`, all `status OK` (`analyze-output.txt`) |
| Wall time | 1.6 s (2026-11-16 01:21:47 to 01:21:49, local time; `analyze-timing.tsv`) |
| `last_update` before (server clock, UTC) | 2026-11-15 16:04:23 to 16:08:11 (`analyze-table-stats-before.tsv`; the time of the build) |
| `last_update` after (UTC) | 2026-11-15 16:21:47 to 16:21:49 (`analyze-table-stats-after.tsv`) |
| Tables whose `n_rows` changed | 8 of 41 |

What `n_rows` was in the tables the estimate reads most (before to after; these are the optimizer's estimates, not counts, the counts are
in `counts.tsv`): `entities` 290,832 to 296,185; `entity_container_data` 175,544 to 180,803; `images` 298,043 to 297,086; `references`
299,088 (unchanged); `joint_permissions` 2,476,907 to 2,706,001.

`dbstats-baseline.tsv` holds, per table of this schema, the read, write, fetch, insert, update and delete counts of the server
(`table_io_waits_summary_by_table`) read at 2026-11-16T01:21:49+09:00 (server uptime 3,584 s at that time), right after the `ANALYZE`; these
counters include the build's own inserts. A later reading is to be compared with this one.

## What this does not say

That the lab can *answer* at this size: no request was made. The time of a request, the plans the optimizer picks at 300,000 rows, and
whether the buffer pool holds what a request reads were not looked at, so that the count stays unseen until the prediction is committed.

## Files

- `build-db-output.txt`: the output of the build command, unedited.
- `counts.tsv`: the row counts. `size.txt`: the sizes of all lab databases.
- `analyze-output.txt`, `analyze-timing.tsv`, `analyze-table-stats-before.tsv`, `analyze-table-stats-after.tsv`: the one `ANALYZE TABLE` run.
- `dbstats-baseline.tsv`: the baseline of the server's statement counters of this database.
