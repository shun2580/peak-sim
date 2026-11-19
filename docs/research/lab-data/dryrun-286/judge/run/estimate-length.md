# Length of the fifth round, estimated from the rules and the earlier records

Written by `judge/estimate_length.py` from committed records. It is an estimate of time, not of any result. No figure of a dry run is used, except the time of the procedure in the section on the rehearsal.

## B at 170,000 rows (s-1 to s-5, 15 steps each)

Step time = the window + the part outside the window + the wait at the end of the step. The window is the smallest multiple of 40 / rps seconds that is at least 300 seconds (spec 7.2); the window rule reproduces the recorded `window_sec` of all 48 steps of the 90,000-row B to within 1 second (the tool rounds a fraction of a second its own way): **yes**. The part outside the window (the discarded first minute and the set-up) was 65 to 125 s over those 48 steps (median 104 s). The wait at the end of a step is the time the load tool waits for requests still running; in the 90,000-row B it was 0 in 19 steps and at most 183 s (the largest). At 170,000 rows with a 1,800-second wait setting it can be as long as 1,800 s per step. The gap between two scenarios (the app and web containers recreated) was 35 s (median of 3 gaps).

| Step k | rps | window (s) | time to the end of step k, shortest (median outside part, no wait) | with the largest outside part and the 90,000-row largest wait (183 s) | upper bound (largest outside part, wait 1,800 s) |
| --- | --- | --- | --- | --- | --- |
| 1 | 0.025 | 1600 | 0 h 28 min | 0 h 31 min | 0 h 58 min |
| 2 | 0.035 | 1143 | 0 h 49 min | 0 h 55 min | 1 h 49 min |
| 3 | 0.05 | 800 | 1 h 04 min | 1 h 14 min | 2 h 35 min |
| 4 | 0.07 | 571 | 1 h 15 min | 1 h 29 min | 3 h 16 min |
| 5 | 0.1 | 400 | 1 h 23 min | 1 h 40 min | 3 h 55 min |
| 6 | 0.15 | 533 | 1 h 34 min | 1 h 54 min | 4 h 36 min |
| 7 | 0.2 | 400 | 1 h 42 min | 2 h 06 min | 5 h 15 min |
| 8 | 0.3 | 400 | 1 h 51 min | 2 h 18 min | 5 h 54 min |
| 9 | 0.45 | 356 | 1 h 58 min | 2 h 29 min | 6 h 32 min |
| 10 | 0.7 | 343 | 2 h 06 min | 2 h 40 min | 7 h 09 min |
| 11 | 1 | 320 | 2 h 13 min | 2 h 50 min | 7 h 47 min |
| 12 | 1.5 | 320 | 2 h 20 min | 3 h 01 min | 8 h 24 min |
| 13 | 2.2 | 309 | 2 h 27 min | 3 h 11 min | 9 h 02 min |
| 14 | 3.3 | 303 | 2 h 34 min | 3 h 21 min | 9 h 39 min |
| 15 | 5 | 304 | 2 h 41 min | 3 h 32 min | 10 h 16 min |

A scenario stops after the first step whose database utilization is 80% or more, or whose intended rps did not come out (spec 7.2), so the number of steps of each scenario depends on its limit rps, which the prediction gives (T4). With the prediction's limit rps per scenario, the time of B is the sum of the table's column at each scenario's stop step, plus 35 s per gap between scenarios (4 gaps).

Three conditions, to bracket the time before the prediction exists (they are conditions, not predictions):

1. **Every scenario stops where it stopped at 90,000 rows** (steps 9, 8, 12, 8, 11 = 48 steps; the time of that record's steps was 10 h 34 min): **10 h 17 min** with the median outside part and no wait, **13 h 01 min** with the largest outside part and the largest wait seen then.
2. **Premise: the limit rps is inversely proportional to the row count**, taken from the measured limits of the 90,000-row B (0.422, 0.256, 1.022, 0.263, 0.928 rps; not verified at 170,000 rows; the third round's limits at 300,000 rows could not be used because that round was undecidable). The limits would be s-1 0.223, s-2 0.136, s-3 0.541, s-4 0.139, s-5 0.491 rps, and the stop steps s-1 step 8, s-2 step 6, s-3 step 10, s-4 step 6, s-5 step 10 (40 steps): **9 h 15 min** with the median outside part and no wait, **11 h 31 min** with the largest outside part and the largest wait seen then.
3. **No scenario stops early** (all 15 steps in all 5 scenarios, which needs every limit above 5 rps): **13 h 53 min** without waits, **51 h 23 min** as an upper bound with a 1,800-second wait at every step. Not expected; written as the bound.

A step that is invalid (the intended rps did not come out) also stops its scenario, so a scenario can end earlier than its limit; the stop at a break (the stop mark) and the later resume add the wait between sessions and are not counted here. Where a route is taken out of the allocation at 170,000 rows (spec 7.4.4) the load of a scenario and so its limit rps change, and the stop steps change with them.

### The length of a step as seen in the rehearsal at 30,000 rows (the procedure only)

The rehearsal of B (`dryrun-286/b/`, 10 steps) took 121 to 122 s outside the window per step (the discarded first minute and the set-up; no request outlived the window, so the wait at the end was 0), and 18 s at most between the end of a scenario and the start of the next (the containers made again; the one gap that holds the deliberate stop at a break is left out). That lies inside the 65 to 125 s of the 90,000-row record above, at its upper end. It is a length of the procedure, which does not depend on the row count; it is not a result and no figure of it is used for a verdict. With the largest outside part seen in the rehearsal (122 s) and the largest gap (18 s):

- every scenario stops where it stopped at 90,000 rows (48 steps): **10 h 31 min** with no wait, **12 h 57 min** with the largest wait seen at 90,000 rows;
- premise 2 (limit inversely proportional to the row count; 40 steps): **9 h 26 min** with no wait, **11 h 28 min** with that wait;
- no scenario stops early (75 steps): **13 h 48 min** with no wait; the upper bound with a wait of 1,800 s at every step is unchanged in kind (51 h 18 min with this outside part).

One scenario of two steps (0.025 and 0.035 rps) took 0 h 49 min from the start of its first step to the end of its second (50 min 5 s from START to END in the chain record, the recreation included), and the five scenarios with one stop at a break in between took 4 h 12 min; a stop at a break adds the time until the mark is read (it waits for the running step) and the restart.

### Building the B database (BookStack, the three tables of the lab chain)

Recorded build seconds (`build-db-output.txt` of each count): 70,000 rows: fill 41.46 s, total 49.06 s; 90,000 rows: fill 48.56 s, total 59.01 s; 150,000 rows: fill 80.37 s, total 87.33 s; 200,000 rows: fill 125.94 s, total 206.13 s; 300,000 rows: fill 190.00 s, total 205.26 s.
170,000 rows lies between the 150,000 count (87 s) and the 200,000 count (206 s) of those records; the estimate is **about 87 to 206 seconds** (a few minutes), plus the check of the counts and `ANALYZE TABLE` (a second or two at 150,000; `analyze-timing.tsv`). The fill time is close to linear in the count over these records; that is read from them, not assumed.

## A at 240,000 rows (the verification app and BookStack, every route, the way of the third run)

The third run (300,000 rows) is the only record of the whole measure in this way. Its recorded seconds (`measured/*/summary.json`, `meta.seconds`, the build included): the verification app 5625 s (1 h 33 min); BookStack 17001 s (4 h 43 min), of which the build of the measuring database was 4,056.73 s (`measured/README.md`). The three extra cold laps of the 22 marked routes (`variance/`, a second database at the same time) took 19,894 s (5 h 31 min), group builds included. In that run the cold-once run of BookStack and the laps ran side by side on separate databases (`order_record.bookstack.txt`).

At 240,000 rows, **premise: the time scales with the row count (a factor of 0.80 from 300,000), except the requests that end at the 600-second limit, which do not scale** (the third run had 1 request over the limit on each application, and `POST /books/{bookSlug}/copy` ran at about 610 s in each of its 3 laps; these stay as they are). On that premise:

| Part | 300,000-row record | 240,000 rows, premise above |
| --- | --- | --- |
| Verification app, build and measure | 1 h 33 min | about 1 h 15 min |
| BookStack, build of the measuring database | 1 h 08 min (4,056.73 s) | about 0 h 54 min |
| BookStack, cold-once measure (without the build) | 3 h 35 min | about 2 h 52 min |
| BookStack, three extra laps of the marked routes (side by side with the cold-once measure) | 5 h 31 min | about 4 h 25 min |

**Shortest** (the verification app first, then BookStack's cold-once measure and the laps side by side as the third run did): about **5 h 40 min**. **Longest** (everything one after the other, no overlap): about **9 h 26 min**. Both on the premise above and with no stop at a break; a resume repeats the measure from the first route of the interrupted run (the third run lost 0 h 54 min that way).

The build of the verification app's measuring database at 240,000 rows is inside its recorded 5625 s above. The A builds are of every table (`a-build/`), which is why they take far longer than the B build.

## What this does not say

- It is an estimate of time. It does not say whether B or A will pass, and uses no figure of a dry run except the time of its procedure.
- Premise 2 of B (limit inversely proportional to the row count) and the A scaling are premises. The prediction of T4 replaces premise 2: its limit rps per scenario gives the stop steps, and the table above gives the time.
- The wait at the end of a step at 170,000 rows is the figure least known: at 90,000 rows it was at most 183 s, and the 1,800-second setting allows far more for the ZIP export.
