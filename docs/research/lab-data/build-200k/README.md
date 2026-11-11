# Can the lab build the database at 200,000 rows? (the build and the count, nothing else)

A check made before any prediction at the new counts (50,000 and 200,000): the lab's builder is run once at 200,000 rows with the same
recipe as the 10,000, 30,000 and 100,000-row databases, and the rows are counted. **No request was sent to the application, no EXPLAIN
was run and no load was applied to this database**; the only statements run on its data are the `SELECT COUNT(*)` of `counts.tsv`
(the sizes in `size.txt` come from `information_schema`, which reads the table metadata). The application was left pointing at the
10,000-row database (`bin/use-db` was not run for this scale).

## Result

| | |
| --- | --- |
| Built | yes, exit 0, no failed table beyond the two the same recipe fails at every scale (`role_permissions`, `permission_role`; see `build-db.log`) |
| Command | `poc/lab/bin/build-db --scale=200000 --n-tables=entities,images,references` (the same recipe as the smaller scales) |
| Started / ended | 2026-11-11 13:55:45 / 13:59:25 (3 min 40 s wall clock, `date` before and after the command) |
| The builder's own seconds | migrations 45.16 s, fill 125.94 s, total 206.13 s |
| Size, `information_schema` | 889.0 MiB (data 396.7, index 492.4) |
| Size, on the database volume | 1,093 MiB for the directory of this database (`du -sm`) |
| Counts | `counts.tsv`: 12 tables at exactly 200,000 rows; `joint_permissions` at 1,800,000 (9 per entity, as at every scale); `entity_container_data` 120,000 and `entity_page_data` 80,000 (the split of the 200,000 entities into containers and pages, as at 30,000: 18,000 and 12,000) |

The same 12 tables are at the scale as in the 100,000-row build (`activities`, `deletions`, `entities`, `entity_permissions`, `favourites`,
`images`, `references`, `search_terms`, `slug_history`, `tags`, `views`, `watches`); every other table has the base size (5 rows, or its
fixed content).

For comparison, the builder's total at 100,000 rows was 63.15 s (migrations 9.38 s, fill 53.36 s). Twice the rows took 3.3 times as long
overall; the fill alone took 2.4 times as long, and the migrations (which do not depend on the row count) took 45 s instead of 9 s, which
is read as the first start of the container runtime after a stop, not as an effect of the scale (not checked).

`size.txt` also lists the other lab databases; the lab's database server keeps all scales at once on one volume, so the whole data
directory (3,082 MiB, with the binary logs) is more than this database alone.

## The version of the generator

The build ran on a working tree with no difference from `HEAD` in the files the builder uses: commit
`98208d106fc906b89ccccfa80527c2e1eda14d43`, and `git status` was empty for the whole tree at the start of the build
(13:55). The builder is `poc/runtime/bin/inner/build-db.php` with `poc/runtime/src/Data/` and `poc/runtime/src/Measure/`; the last commit that changed any of
them is `710264b` (2026-11-09 16:54). If the generator is changed later (for example, the way books grow on a shelf), this database has to be
built again, and this record no longer describes the data.

## What this does not say

That the lab can *answer* at this size: no request was made. The time of a request, the plans the optimizer picks at 200,000 rows, and
whether the buffer pool holds what a request reads were not looked at, so that the count stays unseen until the prediction is committed.

## Files

- `build-db.log`: the output of the build command, unedited.
- `counts.tsv`: the row counts. `size.txt`: the sizes of all lab databases.
