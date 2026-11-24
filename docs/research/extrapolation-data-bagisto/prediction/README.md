# Test A on Bagisto at 240,000 rows: the estimate and the marked routes (made before any measurement at this size)

The estimate of Bagisto for test A (`docs/simulator-spec.md` 7.1 and 7.4.6), made before any measurement at 240,000 rows. The two files the prediction gate reads are
`poc/lab/predictions/extrapolation-bagisto-a-{estimate,marked}.json`; this folder holds where they came from, the list of the 19 routes whose data seems to have arrived, and the checks made
before the commit. Times are the host clock (JST, UTC+9) on 2026-11-24. In the text files the home directory of the working machine is written `~`.

**What this commit fixes, and what it does not.** It fixes the estimate, the list of marked routes (and the `offset_chunk_fit` list inside it) and the list of 19 routes. The routes to be measured are
the 120 routes of the measured list `b6e7042`, **as they are**: the list is not narrowed to the routes the estimate covers, and no route is added afterwards. The denominator of the test is
decided by the routes that have a complete estimate (57), not by how many routes are measured; a route measured without an estimate is listed and not counted. Nothing was measured at 240,000 rows to make
these files: the only things that reached a 240,000-row database are the tool's own build, row counts, `ANALYZE TABLE`, and the `EXPLAIN FORMAT=JSON` statements that `bin/explain-at-scale` lets through (its gate refuses anything else). No HTTP request was made to it.

## The tool and the inputs

- Tool: this repository. The run started at `d99180e` with no uncommitted file under `poc/` (`explain/run.txt`: `uncommitted_files_in_poc_runtime=0`). One commit followed it before this one: `cfab5ab`, which adds `poc/runtime/compose/measure.compose.cap.yaml` (the compose copy that caps the measured app container at 6 GB; sha256 `afcd82766628c0ccaaf50c554f5e056eeff66d65b9af2e7885439b81bcafa4f1`). The estimate tools do not read it. It is the file the measuring step points `PEAK_COMPOSE_FILE` at (decision D251); it is not changed after this commit.
- Capture: the Bagisto capture at 100, 1,000, 10,000 and 30,000 rows (`capture.json` sha256 `928b311eb47da3a58687236d633c2f376920b82a508227e91d587398c6e4a5a4`, `tasks.json` sha256 `c996986b57df28dd20d350037ee406c9ebf0029f503ffaa4e3cce67f5daab814`): 63 routes. The fit was made from the capture's own record (`fits.json`), not refitted from anything else.
- The app tree and the PHP image are the ones the capture used (`peak-measure-php-bagisto:8.4` for the build and the EXPLAIN; the fit, predict, estimate and marked-routes steps ran in the image `peak-measure-php:8.3`, which holds no data and reads files only).

```
bin/fit --from=<capture output> > fits.json
bin/predict --fits=fits.json --at=240000 > predictions-240000.json
bin/explain-at-scale --capture=capture.json --tasks=tasks.json --app=<bagisto tree> --scale=240000 --n-tables=<the 123 tables of docs/research/bagisto-prep/n-tables.txt> \
    --label=n240000 --php-image=peak-measure-php-bagisto:8.4 --keep-stack       # EXPLAIN only; small side = the default 10,000
bin/estimate --capture=capture.json --explain=explain.json --candidate=H1_RANGE --at=240000 --predictions=predictions-240000.json > estimate.json     # run twice
bin/marked-routes --explain=explain.json --estimate=estimate.json > marked.json                                                                    # run twice
```

- The database is the capture's 123-table shared plan stretched to 240,000 rows (`--n-tables`), one database for all 63 routes. **The small side of the plan comparison is the tool's default, 10,000**, the same as in the earlier A rounds; the 3,000 of the 30,000-row dry run belongs to that dry run only. This was decided before the EXPLAIN.
- The build of the 240,000-row database had **no memory cap**: the cap belongs to the measuring step, and the estimate side builds and asks for the plans in one stage with the tool's own compose file.
- Both runs of the estimate are the same bytes, and so are both runs of the marked list (`two-runs.sha256`). The committed files are run 1.
- The estimate file's `small_scale` is 30,000: the tool takes it from the largest captured size, as in the earlier rounds. The EXPLAIN's small side is the 10,000 above.
- The first start (12:35:54) stopped at the fit step with exit 3 because the image `peak-measure-php:8.4` does not exist; no database was touched. The run was started again at 12:36:16 (`order_record.txt`).

## Numbers (from the records)

| What | Value |
| --- | --- |
| Routes in the capture / with a complete estimate | 63 / 57 (6 captured routes have status `partial` and no complete estimate) |
| Statement types in the EXPLAIN | 432, of which 192 were sent as EXPLAIN; `plan_unstable` 26, `plan_changes_at_scale` 55, `explain_unavailable` 0 |
| Marked routes (`plan_changes_at_scale` or `plan_unstable`) | 41 of the 63; of the 57 with a complete estimate, 35 are marked and 22 are not |
| `offset_chunk_fit` | 0 of the 6 routes that have an offset statement (so this test cannot show whether the mark works) |
| Build of the 240,000-row database | 800.82 s in all: migrations 17.57 s, filling 774.59 s (built in 801 s by the run log); 32,391,928 rows by the server's statistics; 136 tables generated, 16 skipped, 0 failed |
| Build of the 10,000-row small database | 74 s |
| Whole EXPLAIN stage | 12:36:17 to 12:51:42 (15 min 25 s) |
| Size at 240,000 rows | data 4.34 GB + index 1.52 GB = 5.86 GB, from `innodb_table_stats` (pages x 16 KiB) read right after `ANALYZE TABLE` (`explain/stats_after_prepare-g1.tsv`, 152 tables; the largest are `orders` 14,201 pages, `product_attribute_values` 14,011 pages with 1,461,120 rows, `agent_conversation_messages` 10,413 pages). `information_schema` was not read: the tool drops the database at the end of its EXPLAIN statements |
| The database volume after the drop and a clean stop | 5,430 MB by `du`, of which the six binary logs are about 5.0 GB, the undo files 0.3 GB, the redo files 0.1 GB (the data files of the dropped database are gone) |
| After the stop | the database log ends with `Received SHUTDOWN ... Shutdown complete`, exit code 0 (no crash recovery is due at the next start). 48 volumes: the 47 earlier ones (none removed) and the one new volume of this run |

## The 19 routes whose data seems to have arrived (`arrived-list/`)

Condition 2 of test A is met only if the count on these 19 routes alone also meets the same line (decision D250). The list was made from the capture's own marks by `arrived-list/make_arrived_list.py`, with no judgment in it, and **made again for this commit**: two runs on the capture gave the same bytes as each other and as the list written when the procedure was first made (57 routes whose estimate can complete, 38 taken out by the capture's mark, 19 left, 6 `partial` in neither). All 19 have a complete estimate in the estimate of this folder. Of the 19, 13 are marked routes and 6 are not (`GET /`, `GET /api/categories/attributes`, `GET /api/categories/attributes/{attribute_id}/options`, `GET /api/core/countries`, `GET /api/customer/addresses/allowed-countries`, `GET /api/customer/addresses/allowed-states`). The count's procedure is `arrived-list/count_listed.py` (`arrived-list/README.md`); it runs the unchanged `judge_a.py` on copies of the estimate and the marked list narrowed to the 19 routes. **Both rules of an undecidable count apply to the count on the 19 routes, as they are applied by the comparison tool to the count of the 57** (a quantity whose denominator has fewer than 5 routes is undecidable; so is a quantity for which more than 20% of the routes with an estimate could not be measured), and an undecidable count does not meet the line (the maintainer's ruling, decision D252). The procedure is unchanged. The number of unmarked routes among the 19 is the denominator of the goal line of that count, so it is small (6), and it is reported with the count. As a fact known before any measurement: of the 19 routes, 6 are not marked, so the goal line of the count on the 19 is decided on at most 6 routes; no lower bound is set for it (decision D250 ④), so it is decided on however many of them are measured. The count on the 19 is undecidable only by the two rules applied to all its routes, quantity by quantity: fewer than 5 routes measured, or more than 20% of the 19 not measured (4 or more). For comparison, the count of the 57 has 35 marked and 22 unmarked routes among its 57 complete estimates, and is undecidable if 12 or more of them cannot be measured.

## Checks made before this commit

- **The gate.** In a throw-away clone with no remote, the two files were refused before they were committed (`PREDICTION GATE CLOSED: ... is not tracked`) and accepted after (`PREDICTION OK`, both files; `gate-rehearsal.txt`). The commit of this folder is what opens it in this repository.
- **The cap file.** `docker compose config` on `measure.compose.cap.yaml` shows `mem_limit` and `memswap_limit` 6442450944 on the measured app container only; the difference from `measure.compose.yaml` is those two lines and two comment lines. The runtime unit tests were run after it was committed: 3,007 tests, 37,379 assertions, OK, none skipped (`runtime-unit-after-cap-copy.log.txt`). The Docker tests were not run, because they remove their own projects' volumes with `--purge`.
- **Before anything was made** (`preflight.txt`): 15,936 MB memory with 12,119 MB available; 885 GB free on `/`; no measurement container running; 47 volumes; the host's update pause read from the registry (paused until 2026-11-28 15:02 UTC, active hours 0 to 18), last planned restart 2026-11-23 02:10.

## Files

- `order_record.txt` (start and end of each step, with times; the first lines name the tool commit and the checksums of the inputs), `preflight.txt`, `gate-rehearsal.txt`, `two-runs.sha256`, `sha256.txt` (every file here and the inputs and outputs that are not kept).
- `fits.json`, `predictions-240000.json`: the fit and its prediction at 240,000 rows (the route-level peak memory and query count; the estimate reads them).
- `explain/`: the run's record (`run.txt`, `run.log.txt`, `eas.stdout.log.txt`, `plan.*`), the two builds' records, and the statistics read after the build. The EXPLAIN's output itself (`explain.json`, 8.3 MB) is not kept in git; its sha256 is in `sha256.txt`.
- `arrived-list/`: the 19 routes, the 38 taken out, the 6 `partial`, the scripts, and their README.
- `*.log` files are not kept in git, so a log is kept with `.txt` added.
