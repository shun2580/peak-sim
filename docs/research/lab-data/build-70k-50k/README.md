# Building the databases at 70,000 and 50,000 rows (the build and the count, nothing else)

The lab's builder was run once at 70,000 rows and once at 50,000 rows with the same recipe as the 3,000 to 200,000-row databases, and
the rows were counted. The counts of the 3,000, 10,000 and 30,000-row databases (the ones the new calibration reuses) were taken the
same way. **No request was sent to the application, no EXPLAIN was run and no load was applied to any of these databases**; the only
statements run on their data are the `SELECT COUNT(*)` of the `counts-<scale>.tsv` files (the sizes in `size.txt` come from
`information_schema`, which reads the table metadata). The application was left pointing at the 10,000-row database (`bin/use-db` was
not run for these scales) with its original settings and no load; `git diff` of `poc/lab/compose` is empty.

## Result

| | 70,000 | 50,000 |
| --- | --- | --- |
| Built | yes, exit 0 | yes, exit 0 |
| Command | `poc/lab/bin/build-db --scale=70000 --n-tables=entities,images,references` | the same with `--scale=50000` |
| Started / ended (`date` before and after) | 2026-11-11 14:05:11 / 14:06:13 (62 s) | 14:06:13 / 14:07:03 (50 s) |
| The builder's own seconds | migrations 7.35 s, fill 41.46 s, total 49.06 s | migrations 7.74 s, fill 29.56 s, total 37.51 s |
| Failed tables | `role_permissions`, `permission_role`: the two the same recipe fails at every scale (`build-db-*-output.txt`) | the same two |
| Counts | 12 tables at exactly 70,000 rows; `joint_permissions` 630,000 (9 per entity); `entity_container_data` 42,000, `entity_page_data` 28,000 | 12 tables at exactly 50,000; `joint_permissions` 450,000; `entity_container_data` 30,000, `entity_page_data` 20,000 |
| Size on the database volume (`du -sm`) | 478 MiB | 394 MiB |
| Size, `information_schema` | 329.9 MiB (data 144.3, index 185.6) | 74.8 MiB (data 29.6, index 45.2): **not usable**, see below |

The 12 tables at the scale are the same as for the 200,000-row build (`activities`, `deletions`, `entities`, `entity_permissions`,
`favourites`, `images`, `references`, `search_terms`, `slug_history`, `tags`, `views`, `watches`); the split of the entities into
containers and pages is 60% / 40%, as at every other scale. Every count in `counts-70000.tsv` and `counts-50000.tsv` equals the scale (or
9 times the scale, 0.6 times, 0.4 times for the three derived tables).

The `information_schema` size of the 50,000-row database is smaller than that of the 30,000-row one (174.6 MiB), which cannot be: the
table statistics were read less than a minute after the build ended and had not been refreshed. It is kept in `size.txt` as read and is
not to be quoted; the `du -sm` figure is the size on disk. The statistics were not refreshed on purpose (that would change what the
optimizer sees before the first request).

## The three databases kept for the calibration

`counts-3000.tsv`, `counts-10000.tsv` and `counts-30000.tsv`: all three exist, and every table listed is at exactly the scale (derived
tables at 9, 0.6 and 0.4 times). They were not rebuilt. Their builds ended before this one began, at these times (database clock, UTC):
30,000 on 2026-11-09 23:29, 3,000 on 2026-11-09 23:37, 10,000 on 2026-11-09 07:24.

## The version of the generator

The two builds ran on commit `577c9652b979cc461ac60dd717d05b4d354fe7ea`, and `git diff HEAD` is empty for the files the builder uses
(`poc/runtime/bin/inner/build-db.php`, `poc/runtime/src/Data/`, `poc/runtime/src/Measure/`). The last commit that changed any of them is
`710264b` (2026-11-09 16:54), the same as for the 200,000-row build. The work tree was not clean (other files had uncommitted changes:
the estimate and prediction code and their tests, none of them read by the builder).

The 10,000-row database was built on 2026-11-09 at 16:24 in local time, 30 minutes before `710264b`; the 3,000, 30,000, 100,000 and
200,000-row databases came after it. `710264b` changed `GeneratorPlanner` only for the shared `--n-tables` plan of `bin/capture` (a new
constructor argument and a new branch taken only when it is given), not the recipe `build-db` passes to the builder, and the counts of
the 10,000-row database follow the same rule as the other scales. That the 10,000-row data is the same as one built today was judged from
the diff and the counts; it was not checked by building it again and comparing.

If the generator is changed later (for example, the way books grow on a shelf), these databases have to be built again, and this record
no longer describes the data.

## What this does not say

That the lab can *answer* at these sizes: no request was made. The time of a request, the plans the optimizer picks, and whether the
buffer pool holds what a request reads were not looked at.

## Files

- `build-db-70000-output.txt`, `build-db-50000-output.txt`: the output of the build commands, unedited.
- `counts-<scale>.tsv`: the row counts for 70,000, 50,000, 30,000, 10,000 and 3,000. `size.txt`: the sizes of all lab databases.
