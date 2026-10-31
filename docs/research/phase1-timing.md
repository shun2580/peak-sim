# Phase 1 analysis time

## Analysis time

Measured with `bin/peak-sim analyze <app> --export-dir=<dir> --stats` (the "elapsed" line on standard error), 5 runs each, PHP 8.3 in Docker (a development WSL host), tool commit `9a92883`. No target value is set yet; these are measurements only, and no threshold is applied.

| App | Routes | Median (s) | Min (s) | Max (s) | Files under app/ | Summaries computed | Summaries reused | Reuse rate | Deepest call depth |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Fixture app | 24 | 0.042 | 0.041 | 0.046 | 42 | 34 | 0 | 0.0% | 4 |
| Synthetic app (300 routes) | 300 | 0.808 | 0.743 | 0.851 | 4551 | 427 | 0 | 0.0% | 4 |

Individual runs, fixture app (s): 0.045, 0.041, 0.046, 0.041, 0.042
Individual runs, synthetic app (s): 0.851, 0.808, 0.752, 0.842, 0.743

The synthetic app is made by `scripts/synthetic-app/generate.php`, which copies the fixture app's controllers and what they use into 300 routes under separate namespaces. Its counts come from the first run; the counts do not change between runs.

## Notes

- Times are wall-clock times of one analysis inside a Docker container on a development WSL machine. They depend on the machine and are only a reference point.
- The fixture app is read through `--export-dir` (no artisan, no database), so the figures cover parsing and analysis only.
- "Summaries reused" is 0 in both apps: every route's methods are computed once. The synthetic app puts each copy in its own namespace, so no summary is shared between copies.
- `--stats` prints the number of files under `app/` (the indexed files) but not a separate count of parses, so no parse count is given here.
