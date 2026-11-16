# Measured records of the third extrapolation test (300,000 rows), stopped at a break

Only what was **finished** before the stop on 2026-11-16 is here. The predictions of this test are the committed files one directory up; nothing in them or in the lab's prediction files was changed.

- `fixtures/` (+ `fixtures.*`, `order_record.txt`, `run_times.txt`, `host_before_measure_fixtures.txt`, `memavail.tsv`): the verification app, every route cold once, at 300,000 rows. 24 of 24 routes tried, exit 0, 2026-11-16T17:31:13 to 19:05:22 (5,625.9 s). Classes: ok 21, exception 1, runner_failed 1, timeout 1. These are raw records; no comparison with the predictions has been run.
- `STOPPED_20261116.txt`: how and when the other runs were stopped, how far each had come, and what the stop left (the two measurement databases no longer exist and must be rebuilt on resume).
- **Not here, on purpose**: the interrupted BookStack runs (the cold-once run had measured 0 routes; the repeat had finished 11 of its 66 planned trials). They are kept outside the repository, are not counted in any pass/fail, and will be redone from their first route.
- `fixtures.wall.txt` has the machine's home path replaced (`~`, `<trials>`); every other file is byte-identical to the original (`sha256.txt`).

No comparison script was run on these records.
