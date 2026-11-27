# peak-sim

A tool that estimates, for a Laravel application, how the cost of each route grows with the size of the data, and which route and which resource would reach its limit first in a busy period.

## Status: version 1.0 is not claimed

This project **does not claim version 1.0**. The conditions it set for 1.0 (section 0 of [`docs/simulator-spec.md`](docs/simulator-spec.md)) are not all met, and they were not loosened to reach it. The verification was stopped on 2026-11-27 and this README says what it covered and where it stops. The full account, with every round and every failure, is in [`docs/research/verification-summary.md`](docs/research/verification-summary.md).

**What was checked is an estimate compared with a measurement made on generated data, on one machine, in a lab, with the application's own limits set as each record says. Nothing was observed on a real deployment, and nothing here is a statement of accuracy on one.**

## What was checked

Two kinds of test, on three applications (a small verification app written for this project, BookStack and Bagisto):

- **A: extrapolation test.** The cost of a cold request (number of queries, rows examined) is estimated from small captures, then compared with a measurement at a much larger row count that the estimate was not fitted on.
- **B: lab backtest.** The database utilization of a busy period is predicted before any load is applied, then compared with the utilization measured in a lab at several request rates.

How each test was run: the prediction was committed before the first request was sent to a database of the test's size; each row count was used once; a failure was not repaired in its own record (a repair was made afterwards and the next round used a new row count); and the verdict was rebuilt from the raw records by two readers who did not see each other's work. Section 6 of the summary gives the `git` commands for checking the order of the commits.

## Results

Latest run of each test. Figures are copied from the records named in the summary.

| Test | Application | Size | Result |
| --- | --- | --- | --- |
| A | Verification app | 240,000 rows | **Pass.** Queries 21 of 21 routes inside ±5%; rows examined 21 of 21 inside ±20% |
| A | BookStack | 240,000 rows (fourth run) | **Pass, thin.** Queries 34 of 34; rows examined 29 of 34 (28 needed). A second reading of the rules, shown beside it, fails (26 of 34) |
| A | Bagisto | 240,000 rows | **Pass.** Queries 54 of 56; rows examined 52 of 56 (45 needed). Two lines of a stricter count have no spare |
| B | BookStack | 180,000 rows (sixth round) | **Pass at the goal.** Mean utilization difference 0.866 points (the naive estimate: 33.351); "over 80% or not" right at 37 of 37 steps; predicted limit rate within ±10% in 5 of 5 scenarios (−1.43% to −4.36%, all slightly low) |
| B | Bagisto | 190,000 rows | **Could not be decided.** The application's container ran out of memory at the seventh step of the first scenario, before the database came near its limit |

Failures that came before the passes were kept: A failed on BookStack three times (rows examined 15 of 23, 22 of 34, 24 of 33) before it passed, and B failed in its first round, was undecidable in its third, and reached the goal in its sixth only after the load tool, the mark for the application's limits and the warm-up had been changed. The sixth round is not a result of one fixed method run six times.

## Limits

- **The lab is not production.** The figures above say how the estimate compared with a lab, not how it would compare with a real deployment. The lab leaves out the cache behaviour of a real deployment, data larger than the database's memory, other work on the same machine and the load of writes. It also has made-up parts of its own (generated data, a fixed small database, limits lifted for the measurement, a web server configuration that was corrected in the Bagisto lab only).
- **Bagisto's B was not measured**, so the condition that the third application passes at the same level is not met.
- **Condition 3 is not met.** 16 routes remained outside the goal in test A; the cause is identified for 1, and 3 of the 16 had no mark at prediction time.
- **Thin and single measurements.** Some passes have a margin of one route. Each test was run once per application at one row count, with a single cold measurement for an unmarked route.
- **Nothing is said about** a real deployment, an application that was not tested, or a row count that was not tested.
- **Not done:** moving the verified estimator into the official tool, packaging it, and checking it on a real deployment's own traffic. These are directions, not results.

## What is in this repository

- `bin/peak-sim` and `src/` are the official tool: a static analysis that classifies how the number of queries and the memory of each route grow with the data. It is separate from the estimator that was verified above.
- `poc/runtime/` and `poc/lab/` hold the estimator and the lab that the verification used: capture of statements at run time, `EXPLAIN` at scale, the prediction of utilization, and the load measurement. They are proofs of concept, not part of the official tool, and are not packaged. See [`poc/runtime/README.md`](poc/runtime/README.md) for the safety conditions.
- `docs/research/` holds the record of every test, round and result, and `docs/decisions.md` the decisions behind them. `docs/simulator-spec.md` is the specification and the conditions for 1.0.

## Using the static analysis

PHP and Composer are run in Docker only; see [`docker/README.md`](docker/README.md) for the development environment and for running the tests on PHP 8.2 and 8.3. The tool needs PHP 8.2 or later and targets Laravel 11 and later (Laravel 11 is best effort; trials on real applications covered Laravel 12 and 13).

```
peak-sim analyze <app-root> [options]

  --artisan-command=<cmd>   how to run artisan (default: php artisan, in <app-root>)
  --export-dir=<dir>        read route-list.json and model-show/*.json exported beforehand
  --format=table|json       output format (default: table)
  --route=<uri>             analyse this one route only
  --max-depth=<n>           deepest call chain to follow (default: 20)
  --memory-limit=<size>     PHP memory_limit for this run, e.g. 1G
  --stats                   print timing and counts to standard error
```

An `unknown` in the output means the static analysis could not follow the code; it is not a statement that the route is safe. The output format is described in [`docs/output-schema.md`](docs/output-schema.md).

The tool does not connect to a production environment or modify the application it reads.
