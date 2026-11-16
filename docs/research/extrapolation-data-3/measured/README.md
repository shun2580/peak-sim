# Measured records of the third extrapolation test (300,000 rows), stopped at a break

Only what was **finished** before the stop on 2026-11-16 is here. The predictions of this test are the committed files one directory up; nothing in them or in the lab's prediction files was changed.

- `fixtures/` (+ `fixtures.*`, `order_record.txt`, `run_times.txt`, `host_before_measure_fixtures.txt`, `memavail.tsv`): the verification app, every route cold once, at 300,000 rows. 24 of 24 routes tried, exit 0, 2026-11-16T17:31:13 to 19:05:22 (5,625.9 s). Classes: ok 21, exception 1, runner_failed 1, timeout 1. These are raw records; no comparison with the predictions has been run.
- `STOPPED_20261116.txt`: how and when the other runs were stopped, how far each had come, and what the stop left (the two measurement databases no longer exist and must be rebuilt on resume).
- **Not here, on purpose**: the interrupted BookStack runs (the cold-once run had measured 0 routes; the repeat had finished 11 of its 66 planned trials). They are kept outside the repository, are not counted in any pass/fail, and will be redone from their first route.
- `fixtures.wall.txt` has the machine's home path replaced (`~`, `<trials>`); every other file is byte-identical to the original (`sha256.txt`).

No comparison script was run on these records.

## Added 2026-11-17: BookStack, every route cold once (resumed run)

- `bookstack/` (+ `bookstack.*`, `host_before_measure_bookstack.txt`, `host_before_repeat_bookstack.txt`): BookStack, the 31 items cold once, at 300,000 rows, started again from the first route on databases built again after the stop. 2026-11-16T23:29:36 to 2026-11-17T04:13:17 (17,001 s), exit 0. 63 routes, 63 tried. Classes: ok 20, unauthenticated 21, not_found 8, exception 7, validation_422 6, timeout 1. These are raw records; the verdict column reads `not_measured` for every route because the run has one scale. Pass or fail is decided by the comparison, which has not been run.
- The run builds a whole 300,000-row database twice: one for the inspection (dropped after the routes are counted) and one to measure on. `bookstack.create_options.tsv` is the table options read from the inspection database.
- **The repeat of the marked routes (22 routes x 3 laps) was still running when these records were added.** Its records and the end times are not here yet; `order_record.txt`, `run_times.txt` and `memavail.tsv` here are still the ones of the stopped runs and will be replaced when the repeat has ended.
- After the stop on 2026-11-16 the Windows host was restarted by hand once (20:18 to 20:19), with nothing running; no measurement was cut by it.
- `bookstack/static.json` (45.5 MB) is a byte copy of the static-analysis input and is not in the repository; its SHA-256 is in `sha256.txt`. `bookstack.wall.txt` has the home path replaced; every other file is byte-identical to the original (`sha256.txt`).

No comparison script was run on these records.
