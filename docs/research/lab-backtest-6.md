# Lab backtest (B), sixth round: B at 180,000 rows with a longer warm-up

Status: **the prediction of B at 180,000 rows was committed at 2026-11-21 20:24:36 (`79e38f2`) and has not been changed. B has been measured (section 3) and its verdict is written (section 8): it passes at the goal, and it is not undecidable. It counts for condition 1b (section 8.4).**

This file is the record of the sixth round of test B (`docs/simulator-spec.md`, sections 7.2, 7.4, 7.4.4 and 7.4.5). It follows `lab-backtest.md` to `lab-backtest-5.md`. The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file.

What is checked is a **check against a busy period reproduced in the lab**: a prediction compared with a measurement made in the lab. Nothing here is observed on a real deployment, and this file does not claim any precision on one.

**In brief:**

1. B was run at **180,000 rows**, which is 6.0 times the largest size the statements were captured at (30,000 rows), and a count that appears in no earlier record as a scale.
2. **Items 1, 2, 3 and 4 are all met at the goal.** The mean absolute difference of utilization is 0.866 points over 29 usable steps (the naive estimate's is 33.351), "over 80% or not" agrees at 37 of 37 steps, and the predicted limit rps is within ±10% of the measured one in 5 of 5 scenarios (−1.43% to −4.36%; in all five the prediction is a little lower).
3. **The one thing that differs from rounds 1 to 5 is the warm-up** (the first part of every step, which is thrown away): **573 s at every one of the 15 steps of the five scenarios**, instead of 60 s. The length was decided from the prediction alone (section 4).
4. **Because of that, this round is not set side by side with rounds 1 to 5**, and no table here compares them (section 7).
5. The number of requests that were still running at the start and at the end of each step's window was recorded, by route. Only requests of one route, `GET /books/{bookSlug}/export/zip` (the ZIP export), were ever running at either edge, and the count at the end differs from the count at the start by at most one request at all 37 steps (section 5).
6. Where the measured utilization is high, the longest request and the number of requests in flight are larger than the single-request time at low load would give, and the measured utilization falls below the prediction only at those steps. This agrees with the reading that heavy requests share the database's two cores (section 6). The record cannot show it is the only cause, and nothing was repaired.
7. Two readers rebuilt the verdict from the raw records without seeing each other's work and agree (section 8.1).
8. s-1 was begun on 2026-11-21 and stopped; that run of s-1 is invalid and not used. All five scenarios were then measured again from the first step of s-1, starting 2026-11-22 00:08 (section 3.2).

## 1. What was tested

B is the test of the database prediction in the lab (spec 7.2): a prediction of the database utilization of each step of five scenarios, made before any step is measured, compared with the utilization measured in the lab. The rules are those of spec 7.2 and 7.4 B; nothing in them was changed for this round except what spec 7.4.5 decided: **the warm-up of each step is at least the longest single request the prediction allows for in that step's mix, and never below 60 s.** The definition of utilization (the database's CPU time inside the window), the pass lines and the judging rules were kept.

- **Row count.** B = 180,000 rows: at least 5 times the capture's largest size, a multiple of 10,000, and the smallest such count that appears in no earlier record (build, estimate, measurement or trial output) as a scale. It was searched for by two people separately and the two agreed (`docs/decisions.md` D239).
- **Settings of B** (the same as the fifth round): the three limits that can end a request are open: PHP `max_execution_time` 0, PHP `memory_limit` 4G, php-fpm `request_terminate_timeout` 0s; nginx `fastcgi_read_timeout` 1800s; the load tool's request timeout 1800s; the wait at the end of a step 1800s. The app container's memory limit is 6 GiB. The application and web containers were made again before each scenario.
- **Scenarios and steps.** Five scenarios (s-1 even mix; s-2 first routes at 80%; s-3 last routes at 80%; s-4 routes 1, 2, 3 and 5 at 80%; s-5 routes 1, 3, 4 and 5 at 80%) and the 15 steps of spec 7.4.2 (0.025 to 5 rps in total). A scenario stops at the first step whose database utilization is 80% or more, or whose intended rps was not reached; later steps are not measured.
- **Calibration** is cal-1 to cal-7 as before. The window and the stopping rules are those of spec 7.2.
- **A dry run** at 30,000 rows (a count seen before) was made first, to find faults of tools and procedures, with the new warm-up read from a prediction file; its numbers are not a test (`docs/research/lab-data/dryrun-22/README.md`).

## 2. The prediction

### 2.1 What it was made from

The prediction file is `poc/lab/predictions/ladder-prediction-6-b180k.json` (sha256 `be0076be…3808`), committed in `79e38f2`.

- The estimate is `9fe817a` (`docs/research/lab-data/estimate-180k/`, committed 2026-11-21 20:22:41): the capture is the same as in the fifth round (largest size 30,000 rows); the small side of the EXPLAIN was 18,000 rows (one tenth of the target). The EXPLAIN ran on a throw-away database, not on the lab's.
- The database of 180,000 rows was built without the application's own rebuild of `references`, and ANALYZE was run on its 41 tables (`docs/research/lab-data/build-180k/`). **No request was sent to it before the prediction was committed**: the server's own statement counters read just before the commit equal those read after the build (`docs/research/lab-data/prediction-6/before-commit/`, difference 0).
- The estimation rules, the calibration (cal-1 to cal-7), the pass lines, the tolerances and the judging rules were not changed after the prediction was committed.

### 2.2 The route taken out of the mix

As in the fifth round, a route whose largest statement carries more values than the placeholder limit of a statement (65,535) is reported as one that will not run and is taken out of the mix of every scenario (spec 7.4.4); its rps is shared among the routes that stay in its group, in proportion to their rps in the scenario file, so the sum of the rps and the naive estimate do not change. This was decided from the prediction alone.

| | |
| --- | --- |
| Route taken out | `GET /books/{slug}`, in **all five** scenarios |
| Reason | placeholders |
| Longest single request predicted | `GET /books/{bookSlug}/export/zip`, upper bound 572.68 s of PHP CPU time (the stop rule is 900 s, half of the request timeout) |

The `allocation.json` written beside each scenario's record is the allocation of the prediction.

### 2.3 The order that makes the test valid

| time (host clock, JST) | what |
| --- | --- |
| 2026-11-21 20:22:41 | `9fe817a`: the row estimate was committed |
| 2026-11-21 20:24:36 | `79e38f2`: the prediction was committed |
| 2026-11-21 20:27:29 | the first launch of the chain of measurements started (stopped and invalid; section 3.2) |
| 2026-11-22 00:08:12 | the second launch started; this is the one that is used |
| 2026-11-22 00:08:46 | the first step of s-1 of the second launch |

The prediction file is unchanged since its commit (`git diff 79e38f2 HEAD -- poc` is empty), and its sha256 was read before the first launch, before the second launch and after the second launch, and was the same each time. The measuring tool was run from a throw-away copy of the repository in which the lab's names were replaced (D240); the step records name the prediction's commit as it is in that copy (`d5aa067`, 2026-11-21 20:24:47, a cherry-pick of the prediction file), and the file's sha256 is the same as in `79e38f2`.

## 3. Measurement

The record is `docs/research/lab-data/ladder-180k/run-2-20261122/`, kept as the tools wrote it; its `README.md` (one level up) lists the files and the few changes made to the copies, and no pass or fail is written in it. Times are the host's clock (JST); the application's logs are in UTC, 9 hours behind.

### 3.1 The scenarios (second launch)

| scenario | first step started | scenario ended | steps measured | last step (rps) | why it stopped | database / application utilization at the last step |
| --- | --- | --- | --- | --- | --- | --- |
| s-1 | 2026-11-22 00:08:46 | 02:58:23 | 7 | 0.2 | database utilization at or above 80% | 86.1% / 35.3% |
| s-2 | 02:58:59 | 05:11:27 | 5 | 0.1 | same | 81.9% / 33.2% |
| s-3 | 05:12:03 | 08:34:19 | 9 | 0.45 | same | 90.0% / 37.1% |
| s-4 | 08:34:55 | 11:06:11 | 6 | 0.15 | same | 90.6% / 36.9% |
| s-5 | 11:06:47 | 14:46:17 | 10 | 0.7 | same | 99.3% / 41.1% |

37 steps were run. At all 37 the intended rps was reached (`dropped` is 0 and the record's validity rule is met). The chain ran for 14 h 38 min 08 s. The limits read from the php-fpm and nginx side before every step were the ones of B at all 37 steps. The statuses of the 2,360 requests sent in the windows of the 37 steps are 200 (2,111) and 302 (249, the OIDC callback, by design); there is no 5xx and no status 0 (no timeout). The write counters were the same before and after every step, the row counts and checksums of the three large tables were the same before and after every scenario, neither the application nor the database was stopped for memory, and the database log had no new line around any scenario.

The application's utilization is 33% to 41% at the last steps, so the application was not the limit; the database's two cores were.

### 3.2 The first launch, the stop, and the second launch

The details are in `docs/research/lab-data/ladder-180k/README.md` and `PAUSE-20261121.md`, and in `docs/decisions.md` D240.

- **The first launch** (2026-11-21 20:27:29) was stopped at 21:44, during step 3 of s-1, for the maintainer's convenience. It is not a failure of the measurement. s-1 of that launch is **invalid as a whole**, is kept in `s-1-180000/` with a note saying so, and is **not used for anything here**. s-2 to s-5 had not been started.
- **The second launch** (2026-11-22 00:08:12) measured s-1 to s-5 again from the first step of s-1, with the same prediction, the same request timeout, the same rules and the same warm-up lengths. The database had been stopped cleanly at the first stop and started cleanly (no crash-recovery line, no error line); the row counts, checksums and table statistics were equal to those taken before the first launch. The write counters of the server start from the server's start, so the restart reset them; that is not counted as a difference (as in the fifth round).
- **After the last step** the compose files were restored to their state before B, the defaults were read again, and the lab was stopped with `compose stop`, keeping the data volume. The database container was stopped with a one-second wait and did not shut down cleanly; whoever starts it again has to read its log for the crash recovery.

## 4. The warm-up

### 4.1 The length, and why

| | |
| --- | --- |
| Warm-up of each step | **573 s**, at all 15 steps of all 5 scenarios (`warmup_sec` in the prediction, and in the record of each of the 37 steps measured) |
| Basis | the route in the stage's mix with the largest upper bound of one request's time: `GET /books/{bookSlug}/export/zip`, 572.68 s, rounded up to a whole second; the upper bound is the PHP CPU time the CPU-time mark gives (`php_cpu_over_db` times the larger of the point and the safe reading of the database time of one request) |
| Minimum | 60 s (never reached: the basis is larger at every stage) |
| Why one number for every stage | the ZIP route stays in the mix of every stage of every scenario (only `GET /books/{slug}` was taken out), and it has the largest upper bound, so the maximum is the same everywhere |
| Window after the warm-up | 343 s to 1,600 s at the 37 steps measured (spec 7.2: the smallest multiple of 40 ÷ rps seconds that is at least 300 s) |
| Length of a step | warm-up plus window; 916 s (0.7 rps) to 2,173 s (0.025 rps) at the steps measured |

573 s is 9.55 times the 60 s of rounds 1 to 5, and the largest the prediction allows, not the largest seen. The check that the lengths follow the rule was made from the prediction file before it was committed, and passed (`docs/research/lab-data/prediction-6/warmup-check.txt`: 612 checks, none failed).

### 4.2 The multiple of the capture's largest size

180,000 ÷ 30,000 = **6.000**. The capture's largest size is the same 30,000 rows as in the fifth round (5.667).

### 4.3 Against the longest request actually seen

- The longest request of a step (the load tool's maximum duration over the requests sent inside the step's window, including those that ended after the window) was **230.9 s**, at s-5 step 10 (0.7 rps, 99.3%). It is 40% of the warm-up. **No request sent in a window of the 37 steps lasted as long as the warm-up.** (Requests sent during the warm-up are not in that figure; the record has no duration for them.) The longest wait for a request to end after a window's end was 157.1 s, at the same step.
- At the steps with a measured utilization below 12% (s-3 steps 1 to 3, s-5 steps 1 to 4) the longest request is 130.5 s to 134.1 s. The record does not say which route the longest request belongs to; the ZIP export is the only route whose single-request time is of that order. The 573 s upper bound is about 4.3 times that low-load time.

## 5. Requests running at the window's start and end

Counted by the load tool's sending side: requests sent before the instant and not answered until at or after it, timed by the load tool and not by php-fpm (`running_requests` in each step's `record.json`). They are a material for reading; they are not used for any item of the verdict.

- **Routes.** At the start and at the end of the windows of all 37 steps, only `GET /books/{bookSlug}/export/zip` had requests running. The seven other routes had none, at any step, at either instant.
- **Count.** At the start: 0 to 7 requests; at the end: 0 to 8. The steps with the highest counts are the last steps of the scenarios: s-1 step 7 (4 at the start, 5 at the end), s-2 step 5 (4, 4), s-3 step 9 (5, 5), s-4 step 6 (5, 5) and s-5 step 10 (7, 8). The steps at 0.025 to 0.07 rps have 0 to 2.
- **Start against end.** The count at the end minus the count at the start is 0 at 30 steps, +1 at 6 steps and −1 at 1 step. So at no step does the window lose or gain more than one request in flight across its edges. In the fifth round, with a 60 s warm-up, this was not recorded.
- **Size of one request at the edge.** One ZIP request in flight carries database work of about half a core for its whole length (D237 gave 0.47 to 0.49 core at three row counts; this round did not measure it). Over about 133 s that is about 65 core-seconds, against 2 × 343 = 686 core-seconds of capacity in the shortest window. This is arithmetic from those two figures, and an upper bound for the share of one request that falls on the wrong side of an edge: the request's work is only partly outside the window.

## 6. What remains after the longer warm-up, and the sharing of the cores

The rules of spec 7.4.5 say: if the differences that remain with a longer warm-up can be explained by heavy requests sharing the database's cores (the reading A-4 of `docs/research/lab-backtest-5-causes.md`, D237), that is a phenomenon a real deployment also has, and putting it into the prediction's formula is to be considered in a later step. **This section reads the record for that; it does not repair anything and it does not judge pass or fail (the verdict is section 8 and does not depend on this section).** What remains to be read is small: the five predicted limits are all a little lower than the measured ones (section 8.3), and the measured utilization falls below the prediction at the top steps.

### 6.1 Predicted against measured, step by step (read, not scored)

The full table is in the appendix. The difference is measured minus predicted, in percentage points.

- At the **32 steps where both the predicted and the measured utilization are below 80%**, measured minus predicted is from −0.23 to +2.38 points, and positive at 29 of the 32. The database also uses about 0.9 point of CPU when it is asked nothing (0.88 to 0.93 point in the records D237 read), which is in the measurement and not in the prediction; this round's own idle reading was not taken out.
- At the **five steps where the measured utilization is 80% or more** (the last step of each scenario) the measured utilization is below the predicted point by 1.87 (s-2 step 5: 81.94% against 83.81%), 3.69 (s-1 step 7: 86.11% against 89.81%), 3.70 (s-4 step 6: 90.59% against 94.29%), 4.32 (s-3 step 9: 90.01% against 94.33%) and 10.77 points (s-5 step 10: 99.30% against 110.07%). The predicted point can be above 100% because the prediction sums the work offered and does not cap it at the two cores; the measurement cannot be above 100%.

### 6.2 Is the stretch of heavy requests in the record?

If heavy requests only ran side by side on free cores, a ZIP request would last about as long as it does alone, and the number of ZIP requests in flight would be about the ZIP arrival rate times that length. If they share two cores that are nearly full, each lasts longer and more of them are in flight. Taking 133 s as the length alone (the low-load figure of 4.3), the record gives:

| step | measured utilization | ZIP rps | in flight expected (rps × 133 s) | in flight observed (start, end) | observed ÷ expected (mean of start and end) | longest request (s) | longest ÷ 133 s |
| --- | ---: | ---: | ---: | --- | ---: | ---: | ---: |
| s-2 step 4 | 59.3% | 0.0187 | 2.48 | 2, 3 | 1.01 | 143.1 | 1.08 |
| s-3 step 8 | 63.1% | 0.0200 | 2.66 | 3, 3 | 1.13 | 144.6 | 1.09 |
| s-4 step 5 | 65.2% | 0.0200 | 2.66 | 3, 3 | 1.13 | 149.7 | 1.13 |
| s-1 step 6 | 67.4% | 0.0214 | 2.85 | 3, 3 | 1.05 | 146.6 | 1.10 |
| s-5 step 9 | 70.6% | 0.0225 | 2.99 | 3, 3 | 1.00 | 150.5 | 1.13 |
| s-2 step 5 | 81.9% | 0.0267 | 3.55 | 4, 4 | 1.13 | 156.7 | 1.18 |
| s-1 step 7 | 86.1% | 0.0286 | 3.80 | 4, 5 | 1.18 | 164.8 | 1.24 |
| s-3 step 9 | 90.0% | 0.0300 | 3.99 | 5, 5 | 1.25 | 172.5 | 1.30 |
| s-4 step 6 | 90.6% | 0.0300 | 3.99 | 5, 5 | 1.25 | 175.0 | 1.32 |
| s-5 step 10 | 99.3% | 0.0350 | 4.65 | 7, 8 | 1.61 | 230.9 | 1.74 |

Read this way: from about 60% to about 70% the stretch is small (1.00 to 1.13 on the count, 1.08 to 1.13 on the longest request); at 82% to 91% it is 1.13 to 1.25 on the count and 1.18 to 1.32 on the longest request; at 99% it is 1.61 and 1.74. This **agrees with** the reading A-4 (a heavy request lasts longer when several run at once on two cores), and with D237's remark that the curve bends from the 56 to 72% steps.

What the record **does not** show:

- The counts are small whole numbers taken at two instants; one request is 0.2 to 0.3 of the expected count at most of these steps. The ratios have that much noise.
- The record has the longest request of a step and not each request's length or route, so the stretch is read from the longest one.
- The length alone (133 s) is taken from steps at low load, not measured in isolation at this count.
- Other causes of a longer request when the database is busy (lock waits, the application's workers queueing, the disk) cannot be told apart from the sharing of cores here. The application's CPU is at 33% to 41% at the last steps, which does not point to the application.
- The step-by-step differences of 6.1 are the sum of this, of the estimate's own error, of rounding (the number of requests sent in a window is a whole number) and of the idle CPU; this file does not split them (D237 split the differences of the fifth round; no such split was made for this round).

### 6.3 Why the five predicted limits are all a little low

The measured limit is the line between the two adjacent steps that cross 80%, so it is fixed by the last two steps of each scenario. Measured ÷ predicted utilization at those two steps:

| scenario | lower step (rps, measured, measured ÷ predicted) | upper step (rps, measured, measured ÷ predicted) |
| --- | --- | --- |
| s-1 | 0.15, 67.41%, 1.001 | 0.2, 86.11%, 0.959 |
| s-2 | 0.07, 59.26%, 1.010 | 0.1, 81.94%, 0.978 |
| s-3 | 0.3, 63.08%, 1.003 | 0.45, 90.01%, 0.954 |
| s-4 | 0.1, 65.24%, 1.038 | 0.15, 90.59%, 0.961 |
| s-5 | 0.45, 70.59%, 0.998 | 0.7, 99.30%, 0.902 |

At the lower steps (59% to 71%) the measurement is 0.998 to 1.038 times the prediction; at the upper steps (82% to 99%) it is 0.902 to 0.978 times. A flat difference in the estimate of one request (D237 found one of 3 to 5% in the fifth round) would show at both steps; here it shows only at the upper ones, where the stretch of section 6.2 also shows. Since the prediction is a straight line through the origin and the measurement bends below it at the top, the measured line reaches 80% at a higher rps than the prediction, in all five scenarios and by 1.4% to 4.4%. That is the direction the reading A-4 gives. It is also the direction that a measurement capped at the two cores, and a little work still in flight past the window's end, would give; the record cannot tell these apart (section 6.2).

**So the record is consistent with the sharing of cores, and it does not prove it.** Whether it is to enter the prediction's formula is a decision for a later step (spec 7.4.5); nothing was changed here.

## 7. This round and the earlier ones

The warm-up of rounds 1 to 5 was 60 s; here it is 573 s. A request longer than 60 s could spill across the start of the window and be cut at its end in the earlier rounds, which is what spec 7.4.5 set out to remove, so the figures of this round measure something slightly different from theirs. **They are not set side by side, there is no table that compares them, and the verdicts of rounds 1 to 5 are not changed.** Condition 1b is counted as spec 7.4.4 counts it (5 times the capture's largest size or more; a count not seen before; passing at the goal).

## 8. Verdict

**B at 180,000 rows passes at the goal: items 1, 2, 3 and 4 are all met at the goal and at the lowest standard. B is not undecidable. It counts for condition 1b.** The prediction used is the committed `79e38f2`, unchanged.

### 8.1 How the verdict was made

Two readers rebuilt the verdict from the record `docs/research/lab-data/ladder-180k/run-2-20261122/` and the prediction file, each without seeing the other's work, and then compared. One ran the committed verdict scripts (`docs/research/lab-data/dryrun-286/judge/`, committed in `53825c8` and `62ba6a2`, **not changed** for this round) and, as a second path, wrote their own formulas from the raw step records, which gave the same figures as the scripts. The other wrote their own formulas and used no verdict script. They agree on the figures below (valid and usable steps, scenarios with a limit, items 1 to 4, the eight steps outside 10% to 90%, and the five relative differences of item 4). The first launch's s-1 (invalid) was not given to either. Their outputs are in `docs/research/lab-data/ladder-180k/judge/` (its `README.md` lists them), and the verdict is recorded in `docs/decisions.md` D241.

The rules are those of spec 7.2 and 7.4 B: the **point** of the prediction is used and not its width; a step is usable for items 1 and 2 when it is valid and its measured utilization is from 10% to 90%; the limit rps is the line between the two adjacent valid steps that cross 80%; B is undecidable if fewer than 10 steps are usable or fewer than 4 scenarios measured a limit.

### 8.2 The steps, and "undecidable"

37 steps were run and all 37 are valid (the rps sent in the window is within ±5% of the decided one, `dropped` is 0). **29 steps are usable.** The 8 that are not: below 10% — s-3 at 0.025 (6.58%) and 0.035 rps (8.84%), s-5 at 0.025 (4.74%), 0.035 (6.29%) and 0.05 rps (8.61%); above 90% — s-3 at 0.45 rps (90.01%), s-4 at 0.15 rps (90.59%) and s-5 at 0.7 rps (99.30%). **The s-3 step at 0.45 rps measured 90.0085%, which is 0.0085 points above the 90% line, so it is not a usable step; it is at the edge of the rule.** For reference only, and not used for anything: had it been counted, the mean of item 2 would be (0.866 × 29 + 4.32) ÷ 30 = 0.98 points, which is still within 5 points. All five scenarios measured a limit rps, by a line between two valid steps (none was extended): s-1 between 0.15 and 0.2 rps, s-2 between 0.07 and 0.1, s-3 between 0.3 and 0.45, s-4 between 0.1 and 0.15, s-5 between 0.45 and 0.7. **Usable steps 29 (10 are needed) and scenarios with a limit 5 (4 are needed): B is not undecidable.**

### 8.3 The items

| item | measured | lowest standard | goal | result |
| --- | --- | --- | --- | --- |
| 1. mean absolute difference smaller than the naive estimate's | point 0.866 points; naive 33.351 points | required | required | met, both |
| 2. mean absolute difference of utilization (29 usable steps) | 0.866 points | 15 points or less | 5 points or less | met, both |
| 3. "over 80% or not" agrees with the measurement (all 37 valid steps) | 37 of 37 = 100% (30 are needed) | 80% or more | 80% or more | met, both |
| 4. predicted limit rps (point) within ±X% of the measured one | 5 of 5 within ±10% and within ±20% (4 are needed) | ±20% in 80% or more of the scenarios | ±10% in 80% or more of the scenarios | met, both |

**Item 3.** There is no step where the prediction and the measurement are on different sides of 80%: all 37 agree. The naive estimate agrees at 32 of 37.

**Item 4, by scenario.** The limit rps is the total rps at which the database utilization reaches 80%. The difference is the predicted point minus the measured limit, over the measured limit.

| scenario | measured limit rps | predicted limit rps (point) | difference of the prediction | within ±10% |
| --- | --- | --- | --- | --- |
| s-1 | 0.18366 | 0.17816 | −3.00% | yes |
| s-2 | 0.09744 | 0.09545 | −2.04% | yes |
| s-3 | 0.39425 | 0.38165 | −3.20% | yes |
| s-4 | 0.12911 | 0.12726 | −1.43% | yes |
| s-5 | 0.53194 | 0.50876 | −4.36% | yes |

**The direction of the difference.** In all five scenarios the predicted limit rps is a little lower than the measured one, by 1.4% to 4.4%: the prediction says the limit is reached slightly earlier than it was. The reading of this is in section 6.3. For reference only, and not used in the verdict, the safe side of the prediction (0.17812, 0.09543, 0.38153, 0.12724, 0.50865) is lower still by a few hundredths of a percent.

### 8.4 Comparison with the naive estimate, and condition 1b

The naive estimate is the one of spec 7.3: the utilization of the calibration scenario with the largest total rps (cal-2, 36.423% for each 1 rps) multiplied by the rps of the step, with no growth of the data and no mix of routes; its limit rps is 2.19641 in every scenario. As the spec says, item 4 is shown for the naive estimate but beating it there is not used for the verdict.

| | point prediction | naive estimate |
| --- | --- | --- |
| items 1 and 2: mean absolute difference of utilization, 29 usable steps | 0.866 points | 33.351 points |
| item 3: "over 80% or not" agrees, 37 valid steps | 37 of 37 | 32 of 37 |
| item 4: limit rps within ±10% of the measured one | 5 of 5 | 0 of 5 |

This is one comparison at one row count (180,000), made once, on one application (BookStack). It does not show how close either would be at a count never seen or on another application.

Spec 7.4.4 counts condition 1b (B) as met only when a B at **5 times the capture's largest size or more**, at a **count not seen before**, **passes at the goal**.

| requirement | this B |
| --- | --- |
| 5 times the capture's largest size or more | met: 180,000 ÷ 30,000 = 6.000 |
| a count not seen before | met: no appearance as a scale in any record before the count was chosen (D239). The verdict reader also searched the tree as it was just before D239 was committed, by the spellings of a scale (`scale`, `rows`, `estimate_at`, `--scale=`, `build-` and the like), and found none; the first appearance is the record of this round's own database build |
| passes at the goal | met: items 1 to 4 at the goal |

**Condition 1b is counted as met by this B.** The numbers 1b is counted with: item 1 yes; item 2 over the 5 points 0.000; item 3 short 0; item 4 short 0 at the goal and at the lowest standard. The 90,000-row B of the fourth round, which also passed at the goal, stays as a reference and was not used here.

### 8.5 The order check

The prediction was committed before the first measurement, by git and by the record: the estimate `9fe817a` at 2026-11-21 20:22:41, the prediction `79e38f2` at 20:24:36, the first launch at 20:27:29 (first step 20:28:04; invalid) and the second launch at 2026-11-22 00:08:12 (first step of s-1 at 00:08:46; the earliest start among the 37 steps used). The prediction file's sha256 is the same at `79e38f2` and at HEAD. The estimate (`9fe817a`) and the prediction (`79e38f2`) are two commits, the estimate first, because the prediction tool requires the estimate to be committed and unchanged; both are before the first measurement (D240, section 2). The warm-up recorded in each of the 37 steps (573 s) is the one in the prediction file for the same stage.

## 9. What this verdict does not say

- It says nothing about a count never seen, and 180,000 is now a count that has been measured: it cannot be used again as a test of anything repaired afterwards (spec 7.5).
- It does not say why a difference remains where one does, beyond the reading of section 6, which is consistent with the sharing of cores and does not prove it.
- It is not a statement about the other test of this round (A) or about another application.

## 10. What the lab cannot say

The limits of the lab are those of `lab-backtest.md` (section 6) and of the later rounds, and are not repeated in full. Those that bear on this round:

- **The lab's data is the generator's.** Its text bodies hold no links to each other, so the application's own rebuild of `references` would leave that table empty at every row count; for that reason the rebuild was run on no database of this round (as in the fifth round). The tables other routes read are as the generator wrote them, and not in the state a real deployment's own maintenance would leave.
- **Cache behaviour of a real deployment**, data that does not fit in the database's memory, contention with other work on the same machine and the load of writes are not in the lab. The routes measured take GET only.
- **B is measured with the application's time limit lifted and the memory limit at 4G**, inside a container limited to 6 GiB, and with a request timeout of 1,800 s. It says nothing about the application stopping early by time or by memory at the defaults. `GET /books/{slug}` was taken out of the mix, so B says nothing about that route at 180,000 rows.
- **The measurement was interrupted once** (section 3.2): the first launch was stopped and is not used, and all five scenarios were measured again from the start. Between the stop and the second launch the database was stopped cleanly and checked; nothing in the records shows an effect on the measured utilization.
- **A long warm-up lets the load reach a steady state; a real busy period also ends.** The measurement says what the database does under a steady load, which is the quantity the prediction gives; it says nothing about the build-up or the tail of a real peak.
- **One row count, once, on one application.** 180,000 rows is used up by this round. B is run on BookStack only; the third application (Bagisto) is a later step.

This file does not claim any precision on a real deployment.

## Appendix. The record read step by step (not scored)

All 37 steps of the second launch. "Measured − predicted" is in percentage points. "ZIP running" is the count at the window's start / end by the load tool's sending side. "Longest request" is the load tool's maximum duration over the requests sent inside the step's window. The predicted figure is the point of the committed prediction. The warm-up is 573 s at every step.

| scenario | step | rps | window (s) | measured utilization | predicted (point) | measured − predicted | ZIP rps | ZIP running at start / end | longest request (s) |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| s-1 | 1 | 0.025 | 1600 | 12.89% | 11.23% | +1.66 | 0.0036 | 0 / 1 | 146.2 |
| s-1 | 2 | 0.035 | 1142 | 15.77% | 15.72% | +0.05 | 0.0050 | 1 / 1 | 132.9 |
| s-1 | 3 | 0.05 | 800 | 22.52% | 22.45% | +0.06 | 0.0071 | 1 / 1 | 130.4 |
| s-1 | 4 | 0.07 | 571 | 32.40% | 31.43% | +0.97 | 0.0100 | 1 / 1 | 134.2 |
| s-1 | 5 | 0.1 | 400 | 46.32% | 44.90% | +1.41 | 0.0143 | 2 / 2 | 137.8 |
| s-1 | 6 | 0.15 | 533 | 67.41% | 67.36% | +0.05 | 0.0214 | 3 / 3 | 146.6 |
| s-1 | 7 | 0.2 | 400 | 86.11% | 89.81% | -3.69 | 0.0286 | 4 / 5 | 164.8 |
| s-2 | 1 | 0.025 | 1600 | 21.02% | 20.95% | +0.07 | 0.0067 | 1 / 1 | 130.2 |
| s-2 | 2 | 0.035 | 1142 | 29.80% | 29.33% | +0.47 | 0.0093 | 1 / 1 | 133.0 |
| s-2 | 3 | 0.05 | 800 | 43.06% | 41.91% | +1.15 | 0.0133 | 2 / 2 | 137.1 |
| s-2 | 4 | 0.07 | 571 | 59.26% | 58.67% | +0.59 | 0.0187 | 2 / 3 | 143.1 |
| s-2 | 5 | 0.1 | 400 | 81.94% | 83.81% | -1.87 | 0.0267 | 4 / 4 | 156.7 |
| s-3 | 1 | 0.025 | 1600 | 6.58% | 5.24% | +1.34 | 0.0017 | 0 / 0 | 130.5 |
| s-3 | 2 | 0.035 | 1142 | 8.84% | 7.34% | +1.50 | 0.0023 | 0 / 0 | 130.8 |
| s-3 | 3 | 0.05 | 800 | 11.42% | 10.48% | +0.94 | 0.0033 | 0 / 1 | 131.1 |
| s-3 | 4 | 0.07 | 571 | 14.49% | 14.67% | -0.18 | 0.0047 | 0 / 1 | 130.9 |
| s-3 | 5 | 0.1 | 400 | 20.74% | 20.96% | -0.23 | 0.0067 | 1 / 1 | 131.1 |
| s-3 | 6 | 0.15 | 533 | 31.60% | 31.44% | +0.15 | 0.0100 | 2 / 1 | 134.6 |
| s-3 | 7 | 0.2 | 400 | 43.53% | 41.92% | +1.61 | 0.0133 | 2 / 2 | 138.1 |
| s-3 | 8 | 0.3 | 400 | 63.08% | 62.89% | +0.19 | 0.0200 | 3 / 3 | 144.6 |
| s-3 | 9 | 0.45 | 356 | 90.01% | 94.33% | -4.32 | 0.0300 | 5 / 5 | 172.5 |
| s-4 | 1 | 0.025 | 1600 | 16.10% | 15.72% | +0.38 | 0.0050 | 1 / 1 | 130.6 |
| s-4 | 2 | 0.035 | 1142 | 22.16% | 22.00% | +0.16 | 0.0070 | 1 / 1 | 130.4 |
| s-4 | 3 | 0.05 | 800 | 32.03% | 31.43% | +0.59 | 0.0100 | 1 / 1 | 133.8 |
| s-4 | 4 | 0.07 | 571 | 45.51% | 44.00% | +1.51 | 0.0140 | 2 / 2 | 137.7 |
| s-4 | 5 | 0.1 | 400 | 65.24% | 62.86% | +2.38 | 0.0200 | 3 / 3 | 149.7 |
| s-4 | 6 | 0.15 | 533 | 90.59% | 94.29% | -3.70 | 0.0300 | 5 / 5 | 175.0 |
| s-5 | 1 | 0.025 | 1600 | 4.74% | 3.93% | +0.81 | 0.0013 | 0 / 0 | 134.1 |
| s-5 | 2 | 0.035 | 1142 | 6.29% | 5.50% | +0.79 | 0.0018 | 0 / 0 | 133.7 |
| s-5 | 3 | 0.05 | 800 | 8.61% | 7.86% | +0.75 | 0.0025 | 0 / 0 | 133.2 |
| s-5 | 4 | 0.07 | 571 | 11.73% | 11.01% | +0.72 | 0.0035 | 0 / 0 | 133.6 |
| s-5 | 5 | 0.1 | 400 | 16.35% | 15.72% | +0.63 | 0.0050 | 1 / 1 | 132.9 |
| s-5 | 6 | 0.15 | 533 | 24.08% | 23.59% | +0.49 | 0.0075 | 1 / 1 | 133.5 |
| s-5 | 7 | 0.2 | 400 | 32.40% | 31.45% | +0.95 | 0.0100 | 1 / 1 | 136.3 |
| s-5 | 8 | 0.3 | 400 | 48.94% | 47.17% | +1.77 | 0.0150 | 2 / 2 | 139.8 |
| s-5 | 9 | 0.45 | 356 | 70.59% | 70.76% | -0.17 | 0.0225 | 3 / 3 | 150.5 |
| s-5 | 10 | 0.7 | 343 | 99.30% | 110.07% | -10.77 | 0.0350 | 7 / 8 | 230.9 |
