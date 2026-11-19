# Dry run of the fifth round (30,000 rows)

**This is a dry run. It is not a test and its numbers are not used for any verdict.** The row count of 30,000 was seen before. Nothing here is a reference value, and nothing here is carried into a prediction, a rule, a pass line or a verdict of the real round (B at 170,000 rows, A at 240,000 rows).

The dry run was made to find out, before any measurement of the real round, whether the whole chain runs from the start to the end: the database build, the inputs of the prediction, the prediction gate, the measurement, the records, and the scripts that read the records and write the verdict. A fault found here is a fault of a tool or a procedure; a number found here is never looked at.

## What was checked, and where it is

| Folder | What | Result |
| --- | --- | --- |
| `b/` | B, the lab backtest: the database of 30,000 rows built without the application's own rebuild of a table, the counts and the table statistics read, the chain of 5 scenarios with 2 steps each, the containers made again between scenarios, the upper limits of the settings read from the server side at each change, the stop at a break and the start again, the logs copied before each recreation, the write and table guards. `RECORD.md` is the summary | Ran to the end. 10 of 10 steps valid. No fault in a tool or a procedure. One setting was added to the tools to make a two-step rehearsal possible (`--max-steps`, off by default) |
| `a/` | A, the extrapolation test: the same door and the same measuring tools as the third run, a few routes of each application, one cold run each and two extra laps of the marked routes, a route that rewrites a table measured after the others of its database. `RECORD.md` is the summary | Ran to the end. No fault in a tool or a procedure |
| `prediction/` | The inputs and the predictions made at 30,000 rows for B and A, and the notes on how the gate was passed. **Kept out of version control on purpose: the rehearsal predictions are not committed**, and the real repository's prediction folder has no change | The gate closes for a file outside the prediction folder, as designed. It opens for the same files committed in a throw-away copy of the repository with no remote |
| `judge/` | The scripts that make the verdict of B, the verdict of A, the table of conditions 1 to 3 and the estimate of the length of the real round. `judge/README.md` describes them | 32 checks against known answers and made-up records pass. Run on this dry run's records, every script wrote its output to the end (below) |
| `judge/run/` | What the scripts wrote when they were run on the records above | See below |

## What the verdict scripts did on the dry run's records

Run on `b/ladder/` with the rehearsal prediction that was measured, and on `a/` with the rehearsal estimates. Only whether the output comes out to the end was checked.

- B: the four items at the lowest standard and at the goal, the conditions of "undecidable" with their numbers, the limit rps of each scenario, and the numbers condition 1b is counted with all came out. The verdict is undecidable, as it must be with 2 steps per scenario.
- A: the verdict per application, the four numbers of condition 1a, the routes outside the goal, and the routes not measured came out for both applications. Both are undecidable, as they must be with a few routes.
- The table of conditions 1a, 1b, 2 and 3 came out. Condition 2 reads "not measured", not 0.
- The faults found while doing this were faults of the scripts (a directory of extra laps that does not exist for an application with no marked route; a mean of `null` when no step is usable; a short number of 0 shown for an undecidable application). They were corrected and the checks run again. No rule was touched. The list is in `judge/README.md`.

## Length of the real round

`judge/run/estimate-length.md` has the estimate and its premises. In short, the length of one step in the rehearsal was the window plus the discarded first minute plus 61 to 62 s, one scenario of two steps took 50 min 5 s, and the chain of five scenarios took 4 h 12 min with one deliberate stop. Because every request ended inside its window here, the wait at the end of a step (up to 1,800 s in the real round) was 0, so these are the shortest. The estimate for the real B is conditional on how many steps each scenario takes, and the estimate for A is conditional on the time growing with the row count.

## What this dry run does not say

- It does not say whether the real round will pass or fail, and its numbers say nothing about the real predictions.
- The prediction with no route taken out (the real 30,000-row allocation) was not measured; the one measured has one route taken out by a made-up memory mark, to make the reallocation run.
- No step above 0.035 rps was measured, so the stopping rules of a scenario (80% utilization, an rps that does not come out) were not exercised.
- The databases of 170,000 and 240,000 rows were not built, and no real prediction was committed, before this dry run.
