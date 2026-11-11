# Lab backtest (B), second round: does the repaired simulator predict the lab at row counts it had not seen?

Status: OUTLINE. Each section below says only what will go into it. No figure is written yet; every figure will be copied from the output of a tool or from a record when its section is filled in.

This file is the record of the second round of test B and of the mark `app_limit_risk` (`docs/simulator-spec.md`, version 0.2, sections 6.4, 7.2, 7.4 and 7.4.1). It follows `docs/research/lab-backtest.md` (the first round, which did not pass) and `docs/research/lab-backtest-causes.md` (where the first round's miss came from). It says what was repaired, what was done in which order, what was measured, and what the numbers can and cannot support. The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file.

What is checked is a **check against a busy period reproduced in the lab**: a prediction compared with a measurement made in the lab. Nothing here was observed on a real deployment, and no value measured in production appears.

## 1. What was repaired

What goes in: each repair made after the first round, one entry per repair, in the order of the causes in `lab-backtest-causes.md`.

- the rule of the row estimate for the routes that were over-estimated, and the decision about the shelf route (is the estimate wrong, or is the lab's data unlike a deployment's);
- the formula, if it changed, the rule by which the formula was chosen, and the commit that fixed that rule before any result was seen;
- the mark `app_limit_risk`: its basis, its threshold, and the commit that fixed them;
- the calibration, measured again with the application's time limit lifted.

For each entry: the commit, and the reason in one or two lines. Nothing is described here that was decided after a result had been seen.

## 2. The check of the repairs (70,000 rows)

What goes in: the estimate set beside one request at a time at 70,000 rows (a row count used for this check only, never for the test), without any other load. For each route of the route list, cold requests, several per route, with the same items as in `lab-backtest-causes.md`: rows read, number of queries, database CPU time. The ratio "estimate ÷ measured" before and after the repair, route by route, and the reading of what improved and what did not.

## 3. Prediction

What goes in: how the prediction was made and committed before any measurement of the new scenarios, and what it contains.

- the five new scenarios (their row counts and mixes are those of section 7.4.1 of the spec), the ladder, the window of a step and the stopping rule (unchanged from the first round);
- the calibration used (its coefficients, residuals and width), the estimate used, and the commits that fixed the formula and the mark's threshold;
- for each scenario and each step: the utilization at the point and on the safe side, the limit rps (point and safe side), the naive estimate, and the marks;
- the hash and the time of the commit of the prediction file, and the time of the first step measured, side by side.

## 4. Measurement (B and the mark)

What goes in: the two measurements, kept apart.

**B** (the application's time limit lifted, so that the database's limit is what is tested):

- the order of work, and what was built for each row count;
- for every step: the intended and the achieved rps, the database utilization, the application's CPU, the dropped requests and the HTTP statuses, and the three settings in force (the limit of the application's run time, the load tool's time limit for one request, the web server's read limit);
- how each setting was changed and how it was put back, with the values read from the side of the application server and not from the command line;
- the steps that were invalid, and why.

**The mark** (the application's time limit at its default):

- at each of the two row counts, one cold request to each route, without load: the HTTP status and the CPU time of the application's process, route by route;
- how the setting was put back, shown in the same way.

## 5. Verdict

What goes in: the verdict of B by items 1 to 4 of section 7.4 of the spec, at the lowest standard and at the goal, and the verdict of the mark by hits, misses and false alarms (the line for passing is no misses; the false alarms are counted and shown). The table of the marks the prediction gave against the routes that were cut. The verdict is made from the raw records alone, and the order of the prediction's commit and the first step is checked in the history and in the records. If the result is a fail or undecidable, the items that missed are recorded and nothing is repaired in this file.

## 6. Comparison with the naive estimate

What goes in: the naive estimate (spec section 7.3) set beside the simulator on items 1 and 4, step by step and scenario by scenario, as in the first round. Whether the simulator beats it is shown; it is not used for the verdict of item 4.

## 7. What the lab cannot say

What goes in: the limits of the lab, as in section 6 of `lab-backtest.md` (cache behaviour, data that does not fit in memory, contention with other work, write load), and what is specific to this round:

- B was measured with the application's time limit lifted, so B says nothing about the application stopping early;
- the mark is checked one request at a time and says nothing about the application under load;
- the lab's data (for example how many books a shelf holds) is the generator's and may differ from a deployment's.

This file does not claim any precision on a real deployment.
