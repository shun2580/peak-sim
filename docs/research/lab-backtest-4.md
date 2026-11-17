# Lab backtest (B) and the mark, fourth round: B at 90,000 rows, the mark at 150,000 rows

Status: **written before B was measured. The prediction of B at 90,000 rows is committed and B has not been measured; the mark at 150,000 rows has been measured and its verdict is written (section 5). The verdict of B is not written (section 6).** This file holds the purpose, the rules, what was changed, the choice of the two row counts, the prediction of B and of the mark as committed, and the measurement and verdict of the mark. The result of B is a heading with "not yet measured" under it. When B is measured, its sections are added below; **nothing above the result of B is rewritten when the figures are added**, and no rule, count, prediction or pass line is changed by this file. Every figure says where it comes from.

This file is the record of the fourth round of test B and of the mark `app_limit_risk` (`docs/simulator-spec.md`, sections 6.2, 6.4, 7.2, 7.4, 7.4.1, 7.4.2 and 7.4.3). It follows `docs/research/lab-backtest.md` (the first round, which did not pass), `docs/research/lab-backtest-2.md` (the second round: B passed at the lowest standard and missed the goal on item 4 by one scenario; the mark failed with one miss) and `docs/research/lab-backtest-3.md` (the third round: the mark passed; B was undecidable because of how the load tool counted requests). The pass lines, the tolerances and the rule for "undecidable" are those of the spec and are not moved by this file.

What is checked is a **check against a busy period reproduced in the lab**: a prediction compared with a measurement made in the lab. Nothing here is observed on a real deployment, and this file does not claim any precision on one.

**In brief:**

- **B at 90,000 rows is a test that is easy by construction** (section 3): 90,000 rows is between two counts already seen (70,000 and 100,000) and is **3 times the capture's largest size (30,000 rows)**. The third round carried the estimate 10 times past the capture. A pass here says less than a pass there.
- **The prediction of B was committed at 2026-11-17 09:08:30 (`e5f406f`), before any step of B and before the first request of the mark** (09:17:22; section 4.4). The limit rps of the five scenarios is 0.2460 to 0.9828 (section 4.1).
- **The mark at 150,000 rows: pass** (spec 7.4.1; no miss). Hits 2 (the ZIP export, stopped by the 30 s run-time limit; the book page, stopped by SQLSTATE 1390), misses 0, false alarms 0, correctly not marked 6 (section 5). Two readers made the counts from the records alone and agreed.
- **How the CPU time of a route that does not run to its end at 90,000 rows is to be read is not yet decided** (section 4.5). This file takes neither reading.

## 1. What was changed

The third round left three things open. The project owner decided on 2026-11-17 how the fourth round deals with them (D216, D217; spec section 7.4.3). Each was committed before any prediction at the new counts was made (section 4.4).

### 1.1 The load tool counts the requests it sent in the window

In the third round the load tool cut off long requests that were still running at the end of a step, at its default of 30 seconds, and counted only the requests that had ended. A route that takes longer than that, the ZIP export, was lost from the count, and B could measure the limit rps for 1 scenario of 5 (`lab-backtest-3.md`, section 5.4). The fix (D220; commits `66d341e`, 2026-11-17 08:27:41, and `7629e9f`, 08:47:25; the record of it in `docs/decisions.md` was committed as `cb4a711`, 08:54:51):

- "Did the intended rps come out" is judged by the number of requests **sent inside the window** (not the number that ended). A request sent at or after the end of the window is not counted for any step.
- A step is invalid when the load tool dropped any arrival (`dropped_iterations` not 0).
- At the end of a step the tool waits for running requests as long as the request's own time limit (600 s), not the default 30 s. The requests that end after the window, and the longest delay, are written in the step's record. The database work of such a request happens after the window and is **not** in that step's database utilization; the record says so.
- The measuring entry refuses a load script that does not report the sent count (exit 2).
- `ladder.php reread` reads a recorded step again under the new rule and shows the result; it writes nothing. Read again, the 56 valid steps of the second and third rounds (29 and 27) give the same counts and the same verdicts, and the 7 invalid steps stay invalid (D220). **The records of the earlier rounds and their verdicts were not changed.**
- One more commit, `9f96913` (2026-11-17 09:59:49), changes only how the measuring entry takes the row count of a scenario: from the prediction file's tables, refusing a disagreeing `--scale`. It touches no prediction, no window, no step rule and no pass rule, and it came **after** the prediction was committed and after the mark was measured (section 4.4).

The tool's own tests, as recorded in D220: a test with the real load tool in a throw-away container (29 checks, passed three times in a row, 0 skipped) and the unit tests (2,772 on PHP 8.3 and on PHP 8.2, 0 skipped). The first version's test had a flaw (a planned request on the very edge of the window, which made the count wobble); it was corrected by moving the plan 1 second off the edges, and the checks were made stricter, not looser (D220).

### 1.2 The mark gets a third side: the number of values in one statement

At 300,000 rows the book page failed in every request with SQLSTATE 1390 (the database refuses a prepared statement with more than 65,535 placeholders), a limit neither the CPU-time side nor the memory side of the mark looks at. The mark now has a third side (D218; commit `e79d7b5`, 2026-11-17 08:29:05): a route is marked when the largest number of values one of its statements carries, at the predicted row count, is above the limit given as an input (`resources.db_placeholder_limit`, 65,535 in this round).

- **The rule:** values in a statement at count n = fixed + ceil((values − fixed) × n / scale), where `values` is the number in the captured representative statement, `scale` the count that statement was captured at, and `fixed` the part outside the list. The premise is that the contents of the list grow in proportion to the table's row count.
- **The basis** is records of counts already seen only: the book page's statement carries 6,004 values at 10,000 rows and 18,004 at 30,000 (4 + 0.6 N). On the seen counts the rule gave 3 hits, 0 misses, 0 false alarms and 6 correctly not marked (the book page at 10,000 to 100,000 not marked, and at 200,000 and 300,000 marked and failed with 1390; the verification app's bulk insert at 30,000 marked and failed). D218 says what was not tested: no record exists between 100,000 and 200,000, and the proportional growth of the other statements is a premise and not a record.
- Without the input, the side is not output and the output is byte for byte what it was. The CPU-time side (factor 9.11) and the memory side (factor 1.51) are not changed.

### 1.3 Nothing else is changed

The calibration is the seven runs `cal-1` to `cal-7` of the second round, as in the third round (`docs/research/lab-data/calibration-r4/fit-7runs/calibration.yaml`; `c_query` 2.84407e-4 s and `c_row` 9.37963e-7 s). The rule of the row estimate, the formula, the ladder, the scenarios s-1 to s-5 (s-4 and s-5 as redrawn on 2026-11-16), the threshold of the database (0.8 with 2 CPUs), the window rule and the rules of the spec are as they were. Every lab database is analyzed with `ANALYZE TABLE` after it is built and before it is used; the estimate assumes a database whose statistics are in order and is not claimed for one whose statistics are stale or missing.

## 2. What is tested

### 2.1 B at 90,000 rows

The rules are those of section 7.4 of the spec (B), section 7.4.1 and section 7.4.3, and are not moved. N_0 = 10,000; the tables of the eight routes (`entities`, `images`, `references`) are grown together to 90,000 rows (9 times N_0).

- **Five scenarios**, all at 90,000 rows, with the mixes of the third round (`lab-backtest-3.md`, section 2.1): s-1 even; s-2 places 1 to 4 at 80%; s-3 places 5 to 8 at 80%; s-4 places 1, 2, 3 and 5 at 80%; s-5 places 1, 3, 4 and 5 at 80%. The shares of the two heavy routes (the ZIP export and the book page) are in section 4.1.
- **The ladder is 15 steps** of total rps (0.025, 0.035, 0.05, 0.07, 0.1, 0.15, 0.2, 0.3, 0.45, 0.7, 1.0, 1.5, 2.2, 3.3, 5.0), a window of the smallest multiple of 40 ÷ rps seconds that is at least 300 seconds after the first minute is discarded, and a scenario stops after the first step whose database utilization is 80% or more or whose intended rps did not come out (outside ±5%; now by the sent count, section 1.1).
- **The settings of B** are those of the third round: PHP's `max_execution_time` 0, php-fpm's `request_terminate_timeout` 0s, the web server's read limit and the load tool's request time limit 600 s, and `memory_limit` 4G, the application container limited to 6 GiB; the app and web containers are recreated before each scenario (spec 7.4.3).
- **The pass lines:** over the valid steps whose measured utilization is from 10% to 90% and the scenarios whose limit rps could be measured: item 1, mean absolute difference smaller than the naive estimate's (required); item 2, 15 points or less (lowest standard) or 5 points (goal); item 3, "over 80% or not" agrees in 80% or more of the valid steps; item 4, the predicted limit rps within ±20% (goal ±10%) of the measured one in 80% or more of the scenarios. B is **undecidable**, not a pass, when fewer than 10 steps can be used for the difference or when the limit rps could be measured for fewer than 4 scenarios. A ratio on the boundary of a tolerance passes.

### 2.2 The mark at 150,000 rows

Measured with the application's limits at their defaults (run-time limit 30 s, memory limit 512M), one cold request per route with no load, one route at a time, for the eight routes. For each route the record holds the HTTP status, the application's CPU time, and which limit stopped the request, read from the application's log. The log is copied **before any container is rebuilt or the stack is taken down**.

- **hit**: marked and cut; **miss**: not marked and cut; **false alarm**: marked and not cut. **Cut** means the request answered HTTP 500 or was stopped by a limit, whatever the cause.
- The line for passing is **no miss**. The number of false alarms is always shown. As in the third round (`lab-backtest-3.md`, section 2.3), the count is **per route**, and which limit cut a request is a separate column and is not part of the count.
- The mark has three sides (CPU time, memory, values in one statement; section 1.2). The spec does not say that the count is made per side. A count per side is shown below for reference and is **not used for the verdict**.

## 3. How the row counts were chosen

The two counts are different, and the reason is written here because it decides how much a result can say.

**The sequence of rulings.** D216 (2026-11-17 08:02) ruled that B and the mark be measured at 150,000 rows, or at 120,000 if the prediction of the marks showed a route that does not run to its end at 150,000, and that the measurement stop if there were such a route at 120,000 too. The prediction of the placeholder side (D218) then showed that **the book page does not run to its end from 109,214 rows upward** (at 150,000 rows 90,008 values against the limit of 65,535; at 120,000 rows 72,007). B is a test of the database's prediction, and it is only that if every route runs to its end. So the count was split (D217; spec section 7.4.3, "the choice of counts, amended"):

- **B at 90,000 rows.** It is the largest multiple of 10,000 not yet measured that is below 85% of the count where the mark starts to be put on the book page (109,214 × 0.85 = 92,832). It was chosen from the prediction only; no measurement was used.
- **The mark at 150,000 rows.** A mark is checked where it is put, and the book page is marked at 150,000.
- If a route that does not run to its end shows at 90,000 rows under the marks (placeholders, CPU time, and memory at B's setting of 4G), nothing is measured and the work stops (D217, item 4). How the CPU-time side is read for that is the open point of section 4.5.

**B at 90,000 rows is an easy test, and this must be read with every result of B.**

- 90,000 is **between 70,000 and 100,000**, two counts already seen. 70,000 is the development count of the second round (not used as a test), and 100,000 is a count of the first round. Records of both exist in the repository and were read when rules were made. B at 90,000 does not ask whether the tool can be carried to a count it has never seen; it asks whether it can be carried to a count between two it has seen.
- **The multiple of the capture's largest size is 3** (90,000 rows against 30,000). The third round's test was 10 times, the second round's two counts were 1.667 and 6.667 (`lab-backtest-3.md`, section 2.4; `lab-backtest-2.md`). The spec (7.4.3) says in the same words that 90,000 rows extends the capture only 3 times and is, so far, easier as a test.
- The mark at 150,000 rows is **5 times** the capture's largest size.
- 90,000 rows has never been measured: no request, load or timing exists for this count in the repository before the prediction (D217; section 4.4).
- A result at 90,000 rows is therefore not a statement about 300,000 rows, and not about any count above 150,000. **A pass of B here does not repair the third round's undecidable result**; the third round's verdict (B undecidable) stands.

## 4. Prediction

### 4.1 The prediction of B at 90,000 rows

The prediction was committed on 2026-11-17 at 09:08:30 as `e5f406f`, in one commit with the prediction of the mark (section 4.2), before any step was measured (section 4.4). The figures below are copied by a script from the committed file `poc/lab/predictions/ladder-prediction-4-b90k.json`; none is typed by hand. They are a prediction and nothing here has been compared with a measurement.

**How its input differs from the third round's** (`poc/lab/predictions/ladder-prediction-4-b90k-input.yaml`, made from the third round's input by changing the count of the five scenarios and the three lines under `resources:`; the calibration part and the mixes are unchanged):

| input | third round (300,000 rows) | this prediction (90,000 rows) |
| --- | --- | --- |
| run-time limit of the application (`app_cpu_time_limit`) | 30 s | **not given** (B runs with the limit lifted; so no CPU-time mark is output) |
| memory limit (`app_memory_limit`) | 512M | **4G** (B's setting; 4,294,967,296 bytes) |
| values in one statement (`db_placeholder_limit`) | not given | 65,535 |

This is a different shape from the third round's prediction, which was made with the defaults (30 s, 512M) so that its marks could be read against the limits of the mark's measurement. Here the marks are read against B's own settings, because B is measured with the limits lifted. The mark measured at 150,000 rows is predicted in a separate file (section 4.2) with the defaults.

**What it is made from:** the calibration `cal-1` to `cal-7` of the second round (two coefficients, as in section 1.3); the row estimate at 90,000 rows, `docs/research/lab-data/estimate-90k/estimate-90000.json` (SHA-256 `c6f5a2e622cbf7d90177b8dba95373ce373dd5b99a2edb8304e336fa40c92587`, commit `a2598db`, 2026-11-17 09:07:47), made with the same tool and rules as the 300,000-row estimate and the flag `--with-placeholders` and without any request; the scenario file above (the prediction records its SHA-256 as `152fb489515b6112e8285d68041bfb73fde3300b100d8afa1f6579994f70d7db`). The naive estimate is the one of `cal-2` as in the third round (utilization 0.3642 at 1.0 rps, count 10,000).

**The limit rps.** The total rps at which the database's utilization reaches 80%: the point, the safe side (upper), the naive estimate, and the range of the point over the seven fits made by leaving one calibration scenario out (a spread of the calibration only; it does not include the estimate's error). The shares of the two heavy routes are computed from the scenario file.

| scenario | point | safe side | naive | range over the 7 leave-one-out fits | share of the ZIP export | share of the book page |
| --- | --- | --- | --- | --- | --- | --- |
| s-1 | 0.3935 | 0.3489 | 2.1964 | 0.3929 to 0.3945 | 0.125 | 0.125 |
| s-2 | 0.2460 | 0.2181 | 2.1964 | 0.2456 to 0.2466 | 0.2 | 0.2 |
| s-3 | 0.9828 | 0.8715 | 2.1964 | 0.9814 to 0.9855 | 0.05 | 0.05 |
| s-4 | 0.2523 | 0.2358 | 2.1964 | 0.2519 to 0.2530 | 0.2 | 0.05 |
| s-5 | 0.8933 | 0.6701 | 2.1964 | 0.8920 to 0.8955 | 0.05 | 0.2 |

The naive estimate is the same for all five scenarios, because it knows nothing of the row count or the mix.

**The marks of the prediction.** In this prediction no route is marked: the CPU-time side is not output (no run-time limit in the input), and on the memory side (limit 4G) and the placeholder side (limit 65,535) the marked list is empty. The values:

| route | memory side: bound (x1.51) / 4G | placeholder side: values in the largest statement / 65,535 |
| --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 0.0098 | 20 (0.0003) |
| `GET /books/{bookSlug}/export/zip` | 0.0578 | 10 (0.0002) |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 0.0094 | 20 (0.0003) |
| `GET /books/{slug}` | 0.1195 | **54,006 (0.8241)** |
| `GET /books/{slug}/references` | 0.0095 | 20 (0.0003) |
| `GET /images/edit/{id}` | 0.0014 | 2 (0.00003) |
| `GET /oidc/callback` | 0.00001 | not evaluated (no placeholder figure) |
| `GET /shelves/{slug}` | 0.0001 | 20 (0.0003) |

**The book page is 82.4% of the placeholder limit** (54,006 values against 65,535), the closest of any route to a line, and it is the route that fell at 150,000, 200,000 and 300,000 rows. The rule's premise (that the list grows in proportion to the rows) is a premise and not a record between 100,000 and 200,000 rows (D218). The margin above is 17.6% of the limit.

The prediction's top-level `marked_routes` list (the ZIP export, the book page and `GET /shelves/{slug}`) is a different mark: it is the list of routes whose plan changes or is unstable in the EXPLAIN (spec section 7.1; `docs/research/lab-data/estimate-90k/marked-routes-90000.json`, SHA-256 `3039993e422c55db2d9080ff67bde2856c6f38de3a722b9743f60e57d8877ffc`). It is for the extrapolation test, not the mark `app_limit_risk` that is scored in section 2.2, and the two are not mixed in any count.

**The predicted utilization, step by step.** For each scenario, the point, the safe side (upper) and the naive estimate at each step as a percentage. Each table ends at the first step at which both the point and the safe side are 80% or more, because a scenario stops there; the steps above it are in the file.

**s-1**

| total rps | point | safe side (upper) | naive |
| --- | --- | --- | --- |
| 0.025 | 5.1% | 5.7% | 0.9% |
| 0.035 | 7.1% | 8.0% | 1.3% |
| 0.05 | 10.2% | 11.5% | 1.8% |
| 0.07 | 14.2% | 16.1% | 2.5% |
| 0.1 | 20.3% | 22.9% | 3.6% |
| 0.15 | 30.5% | 34.4% | 5.5% |
| 0.2 | 40.7% | 45.9% | 7.3% |
| 0.3 | 61.0% | 68.8% | 10.9% |
| 0.45 | 91.5% | 103.2% | 16.4% |

**s-2**

| total rps | point | safe side (upper) | naive |
| --- | --- | --- | --- |
| 0.025 | 8.1% | 9.2% | 0.9% |
| 0.035 | 11.4% | 12.8% | 1.3% |
| 0.05 | 16.3% | 18.3% | 1.8% |
| 0.07 | 22.8% | 25.7% | 2.5% |
| 0.1 | 32.5% | 36.7% | 3.6% |
| 0.15 | 48.8% | 55.0% | 5.5% |
| 0.2 | 65.0% | 73.4% | 7.3% |
| 0.3 | 97.6% | 110.1% | 10.9% |

**s-3**

| total rps | point | safe side (upper) | naive |
| --- | --- | --- | --- |
| 0.025 | 2.0% | 2.3% | 0.9% |
| 0.035 | 2.8% | 3.2% | 1.3% |
| 0.05 | 4.1% | 4.6% | 1.8% |
| 0.07 | 5.7% | 6.4% | 2.5% |
| 0.1 | 8.1% | 9.2% | 3.6% |
| 0.15 | 12.2% | 13.8% | 5.5% |
| 0.2 | 16.3% | 18.4% | 7.3% |
| 0.3 | 24.4% | 27.5% | 10.9% |
| 0.45 | 36.6% | 41.3% | 16.4% |
| 0.7 | 57.0% | 64.3% | 25.5% |
| 1.0 | 81.4% | 91.8% | 36.4% |

**s-4**

| total rps | point | safe side (upper) | naive |
| --- | --- | --- | --- |
| 0.025 | 7.9% | 8.5% | 0.9% |
| 0.035 | 11.1% | 11.9% | 1.3% |
| 0.05 | 15.9% | 17.0% | 1.8% |
| 0.07 | 22.2% | 23.7% | 2.5% |
| 0.1 | 31.7% | 33.9% | 3.6% |
| 0.15 | 47.6% | 50.9% | 5.5% |
| 0.2 | 63.4% | 67.9% | 7.3% |
| 0.3 | 95.1% | 101.8% | 10.9% |

**s-5**

| total rps | point | safe side (upper) | naive |
| --- | --- | --- | --- |
| 0.025 | 2.2% | 3.0% | 0.9% |
| 0.035 | 3.1% | 4.2% | 1.3% |
| 0.05 | 4.5% | 6.0% | 1.8% |
| 0.07 | 6.3% | 8.4% | 2.5% |
| 0.1 | 9.0% | 11.9% | 3.6% |
| 0.15 | 13.4% | 17.9% | 5.5% |
| 0.2 | 17.9% | 23.9% | 7.3% |
| 0.3 | 26.9% | 35.8% | 10.9% |
| 0.45 | 40.3% | 53.7% | 16.4% |
| 0.7 | 62.7% | 83.6% | 25.5% |
| 1.0 | 89.6% | 119.4% | 36.4% |

At this count a ladder of the five scenarios reaches 80% inside the 15 steps in every scenario, so the limit rps can be found by a line between two valid steps in each one (spec 7.2). Whether that holds in the measurement is for the measurement to show.

### 4.2 The prediction of the mark at 150,000 rows

The mark is a property of each route, so the mix of routes does not matter for it. It is predicted in its own file, `poc/lab/predictions/ladder-prediction-4-mark150k.json` (with its input `ladder-prediction-4-mark150k-input.yaml`), committed in the same commit `e5f406f`. **The file holds one scenario, and its id is `s-1`**; its contents are the mark of the eight routes at 150,000 rows, and its `s-1` is the name of the prediction tool's scenario (the even mix is used because the mark does not depend on it) and **not** an `s-1` of test B. Its input has the defaults the mark is measured at: run-time limit 30 s, memory limit 512M (536,870,912 bytes) and 65,535 values in one statement. It is made from the row estimate at 150,000 rows, `docs/research/lab-data/estimate-150k/estimate-150000.json` (SHA-256 `c8f364b9e2775ef93133c063a519537c8e197ce03e5c7b0f853e24b327fadff7`, commit `a2598db`).

Two routes are marked; the other six are not marked on any side. The value in each cell is the bound divided by the limit; a route is marked from 1.

| route | CPU-time side (bound / 30 s) | memory side (bound / 512M) | placeholder side (values / 65,535) | marked |
| --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 0.0008 | 0.1398 | 22 values (0.0003) | no |
| `GET /books/{bookSlug}/export/zip` | **15.9074** | 0.7664 | 12 values (0.0002) | **yes (CPU time)** |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 0.0008 | 0.1341 | 22 values (0.0003) | no |
| `GET /books/{slug}` | 0.5604 | **1.5844** | **90,008 values (1.3734)** | **yes (memory and placeholders)** |
| `GET /books/{slug}/references` | 0.0007 | 0.1376 | 22 values (0.0003) | no |
| `GET /images/edit/{id}` | 0.0006 | 0.0113 | 2 values (0.00003) | no |
| `GET /oidc/callback` | 0.0000 | 0.0001 | not evaluated | no |
| `GET /shelves/{slug}` | 0.0019 | 0.0010 | 30 values (0.0005) | no |

The prediction file's top-level list of marked routes at 150,000 (the book page and `GET /shelves/{slug}`) is the EXPLAIN mark of section 4.1 and not this one; the marks scored are those in the table, read from the three blocks `app_limit_risk`, `app_limit_risk_memory` and `app_limit_risk_placeholders`.

### 4.3 The multiple of the capture's largest size

The estimates are made from captures at 100, 1,000, 10,000 and 30,000 rows. The row count of B is 90,000, **3 times** the capture's largest size; the row count of the mark is 150,000, **5 times**. The third round was 10 times (`lab-backtest-3.md`, section 2.4). Every agreement or disagreement below is a result of carrying the estimate 3 times (B) and 5 times (the mark) past what it was captured from, and is not a statement about 300,000 rows or more.

### 4.4 What was fixed before the prediction, and the order that makes the test valid

| time | commit | what |
| --- | --- | --- |
| 2026-11-17 08:02 | D216 (committed 08:07:49 as `5e88409`) | the ruling on the fourth round: fix the load tool, add the placeholder side, 150,000 rows (or 120,000), neither the estimate's rule nor the calibration changed |
| 2026-11-17 08:07:36 | `88b7420` | spec section 7.4.3 |
| 2026-11-17 08:27:41 | `66d341e` | the load tool counts the requests sent in the window (D220) |
| 2026-11-17 08:29:05 | `e79d7b5` | the placeholder side of the mark (D218) |
| 2026-11-17 08:39:24 (file saved) / 08:42:01 (committed) | `5d1f790` | spec section 7.4.3 amended: B at 90,000 rows, the mark at 150,000 rows (D217). The wording of the spec gives the time of the ruling as 08:45; the file was saved at 08:39:24 and committed at 08:42:01. Both are given here and the spec's wording is not changed |
| 2026-11-17 08:42:43 | `4e4cac8` | D217 to D219 in `docs/decisions.md` |
| 2026-11-17 08:47:25 | `7629e9f` | the sent-count test kept off the window edges (D220) |
| 2026-11-17 08:54:51 | `cb4a711` | D220 in `docs/decisions.md` |
| 2026-11-17 08:55:25 to 08:59:41 | `a2598db` | the lab databases at 90,000 and 150,000 rows were built, counted (12 tables at exactly the count; `joint_permissions` 9 times) and analyzed once (records `docs/research/lab-data/build-90k/` and `build-150k/`); no request, no EXPLAIN on them |
| 2026-11-17 09:00:19 to 09:05:36 | `a2598db` | the row estimates at 90,000 and 150,000 rows (EXPLAIN on throw-away databases, not on the lab's) |
| 2026-11-17 09:07:47 | `a2598db` | the builds and the estimates committed |
| **2026-11-17 09:08:30** | **`e5f406f`** | **the two prediction files and their inputs** |

The order, as in the third round (`lab-backtest-3.md`, section 3.4):

1. The rules, the tool fix and the placeholder side are committed first. The placeholder side is chosen from records of counts already seen and nothing at the new counts.
2. Until the prediction is committed, the two lab databases are only **built, counted, analyzed once and read for the estimate's inputs** (`ANALYZE`, the statistics tables, `EXPLAIN` on throw-away databases). No request, no load, no timing. The counters of the lab databases read just before the prediction's commit show no statement since the baseline taken after the `ANALYZE` (`build-90k/dbstats-before-commit.tsv` and `build-150k/dbstats-before-commit.tsv`, committed in `e5f406f`).
3. The prediction is committed. The measuring entries open only when the prediction file is in HEAD, has no difference in the working tree, and the directory of predictions holds no other changed or untracked file (`bin/prediction-gate`).
4. Only then is anything measured at 90,000 or 150,000 rows. The prediction, the estimate, the calibration, the formula, the ladder, the scenarios, the marks' rules and the rules of the spec are **not changed** after the commit. If a step is later found to be wrong, it is recorded and reported, and not repaired.

| | |
| --- | --- |
| Commit of the prediction files | `e5f406f8341803fe287fdf04f4636706e722fbd5` (`ladder-prediction-4-b90k.json`, `ladder-prediction-4-mark150k.json` and their inputs) |
| Commit time | 2026-11-17 09:08:30 (+0900) |
| First request sent to the 150,000-row database (the mark) | 2026-11-17 09:17:22 (`mark-times.txt`, START) |
| Interval between the two | 8 min 52 s |
| Last commit that touched the prediction files | `e5f406f` (no later commit changes either file or its input) |
| First step of B | not yet started |

`9f96913` (the measuring entry takes its row count from the prediction file; section 1.1) was committed at 09:59:49, after the prediction and after the mark's measurement. Between `e5f406f` and `9f96913` no file changed in `poc/lab/predictions`, in the prediction gate, in the table checker, in `poc/runtime/src/Estimate`, in `poc/runtime/src/Simulate` or in the spec (checked with `git diff --stat`); the changes are to the measuring entry, the load tool's ladder code, their tests and the records of the mark.

### 4.5 A reading that is not yet decided: the CPU time of a route that does not run to its end at 90,000 rows

Spec section 7.4.3 and D217 (item 4) say that if a route that does not run to its end shows at 90,000 rows under the marks "(placeholders, CPU time, and memory at B's setting of 4G)", nothing is measured and the work stops. The parenthesis names the memory limit at B's setting, 4G, and says nothing about which run-time limit the CPU-time side is read against. Two readings exist:

- **Read against B's own settings** (the run-time limit lifted): the CPU-time side has no limit to be marked against. This is how the prediction of B was made (section 4.1: no run-time limit in the input). Under it no route is marked at 90,000 rows.
- **Read against the default 30 s**, as the mark of the third round was: the committed estimate at 90,000 rows gives bounds of about 10.0 times the limit for the ZIP export and about 1.1 times for the book page (recomputed here from the committed estimate and the committed coefficients with the factor 9.11, as the larger of the point's and the upper side's database time; these figures are **not** in the prediction file, which has no CPU-time side). Under it both would be marked.

**The reading is not yet decided.** The prediction is committed and cannot be changed. This file records the two readings and takes neither; the section is rewritten only by adding the decision when it is made, and **B is not measured by this file's author before it is made**. What follows for the measurement of B is written under section 6 when it happens.

## 5. The mark at 150,000 rows: measurement and verdict

Times are the host's clock on 2026-11-17; the application's logs are in UTC, 9 hours behind. The record is `docs/research/lab-data/mark-150k/` (committed as `d4c350f`, 09:21:47). It is kept as the tools wrote it, and no pass or fail is written in it: this file is where the verdict is first written.

### 5.1 What was measured, and when

- The prediction file was in HEAD as `e5f406f` (09:08:30) and `bin/prediction-gate` printed `PREDICTION OK` for it at 09:13, before the mark.
- The lab's limits were not changed for the mark: the compose files of the lab had no difference from HEAD before and after, and `bin/check-limits --require=limited`, read through php-fpm and nginx before (09:14:19) and after (09:20:49) the mark, gave `max_execution_time` 30, `memory_limit` 512M, `request_terminate_timeout` 0s and nginx's `fastcgi_read_timeout` 120s both times.
- 09:14:04 the app and web containers were recreated and pointed at the 150,000-row database (exit 0). The app container's memory limit was 6,442,450,944 bytes before and after, `OOMKilled` false, restart count 0.
- A first call of the measuring script at 09:14:27 stopped before any request because the file of targets for 150,000 rows did not exist; it was made by `bin/targets --scale=150000` at 09:14:46 (8 of 8 routes filled; no request sent).
- **The mark ran from 09:17:22 to 09:20:28** (exit 0): for each route one warm-up request (rep 0) and one cold request (rep 1), one at a time, no load, the load tool's timeout 180 s. The count of `entities`, `images` and `references` at 09:17:23 was 150,000 each. No `ANALYZE` was run; the statistics tables of the database were read before and after and no row differs (`stats-mark-150000.tsv`).
- The application's log and the web server's log were copied at about 09:20:3x, before any container was recreated or the stack was taken down (`app-log-after-mark.txt`; the web server's log is empty).

### 5.2 What each request did (rep 1, the cold request used for the verdict)

PHP CPU time is the tool's `php_cpu_usec` (the application container's CPU time over the request with the idle rate taken off).

| route | predicted mark | HTTP | time | PHP CPU time | stopped by (from the log) | class |
| --- | --- | --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | no | 200 | 36 ms | 0.036 s | - | correctly not marked |
| `GET /books/{bookSlug}/export/zip` | yes (CPU time) | **500** | 52,850 ms | 30.458 s | the 30 s run-time limit | **hit** |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | no | 200 | 41 ms | 0.042 s | - | correctly not marked |
| `GET /books/{slug}` | yes (memory and placeholders) | **500** | 18,984 ms | 17.472 s | SQLSTATE 1390 (placeholders) | **hit** |
| `GET /books/{slug}/references` | no | 200 | 26 ms | 0.026 s | - | correctly not marked |
| `GET /images/edit/{id}` | no | 200 | 21 ms | 0.022 s | - | correctly not marked |
| `GET /oidc/callback` | no | 302 | 12 ms | 0.011 s | - | correctly not marked |
| `GET /shelves/{slug}` | no | 200 | 48 ms | 0.037 s | - | correctly not marked |

The warm-up requests (rep 0) gave the same statuses; the ZIP export's rep 0 took 53,388 ms with a PHP CPU time of 30.732 s, and the book page's rep 0 took 21,929 ms with 20.327 s.

**Which limit ended each request** (`app-log-after-mark.txt`, from the application's start at 09:14:05 to the end of the mark). The log holds four error entries, the same number and the same order as the four HTTP 500 answers:

- `PHP Fatal error: Maximum execution time of 30 seconds exceeded`: **2 entries**, 09:18:34 and 09:19:30 on the host clock (the ZIP export, rep 0 and rep 1).
- `SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders`: **2 entries**, 09:19:56 and 09:20:18 (the book page, rep 0 and rep 1; the failing statement is the `select * from activities where ((loggable_type = book and loggable_id in (1)) or (loggable_type = chapter and loggable_id in (2, 7, 12, ...`).
- `Allowed memory size ... exhausted`: **0 lines**, and the app container was not stopped for memory.
- nginx's 120 s limit ended no request (the longest answer was 53.4 s).

The lines of the log carry no URL, so each entry is matched to its request by order and by time (the entries are 56 s apart for the two of the ZIP export and 22 s apart for the two of the book page, which agrees with the request times). That is a matching by order and not a read of the route from the log.

### 5.3 The verdict

By the rule of section 2.2, counted per route as in the third round:

| | |
| --- | --- |
| Hits (marked and cut) | **2** (the ZIP export and the book page) |
| Misses (not marked and cut) | **0** |
| False alarms (marked and not cut) | **0** |
| Correctly not marked (not marked and not cut) | 6 |
| **The mark** | **pass** (spec 7.4.1: no miss) |

**The count was made by two readers, each working alone from the raw records** (the prediction file, the request records, the application's log and the rules of the spec), without having seen the other's result; both gave 2 hits, 0 misses, 0 false alarms and 6 correctly not marked, and both gave the same limit for each of the two cut routes.

**Which limit cut the request, and whether it was the limit the mark came from** (the separate column, not part of the count):

- The ZIP export was stopped by the 30 s run-time limit and was marked on the CPU-time side. Its PHP CPU time was 30.458 s against the limit of 30 s; the CPU-time side of the prediction was 15.9074 times the limit.
- The book page was stopped by the placeholder limit (1390) and was marked on the placeholder side (and on the memory side). Its PHP CPU time was 17.472 s, below the 30 s limit; the CPU-time side of the prediction for this route was 0.5604 of the limit (16.81 s). The request stopped at the failing statement and so is not a measurement of a request that runs to its end.

**A count per side, for reference** (the spec does not say that the count is made per side; it is not used for the verdict):

| side | hits | misses | false alarms | note |
| --- | --- | --- | --- | --- |
| CPU time | 1 (the ZIP export) | 0 | 0 | |
| memory | 0 | 0 | 0 | the book page is marked on this side and was stopped by 1390 before the memory limit could be reached, so whether the memory side would have been right is **not observable** here; counted as a false alarm it makes 1 |
| placeholders | 1 (the book page) | 0 | 0 | `GET /oidc/callback` has no placeholder figure and is not evaluated |

No request that was cut was left unmarked on the side of the limit that cut it (0). The verdict is the same under the per-route count and under the per-side count: **no miss**.

**What the measurement shows, as facts.** At 300,000 rows both the ZIP export and the book page were ended by the memory limit in the third round's mark (`lab-backtest-3.md`, section 5.3); at 150,000 rows the ZIP export was ended by the 30 s run-time limit and the book page by SQLSTATE 1390, and no request was ended by memory. The log does not say why the limit that ended a request is not the same at the two counts; that is not read from these records.

### 5.4 What this verdict does not say

- It is one cold request per route, one at a time and without load, and each route is n = 1 (one cold request; the warm-up gave the same). The measurement says nothing about repeatability, about the application under load or about a second request to the same route.
- The memory side of the mark was **not checked** at this count against a measured peak: no request was ended by memory and the lab did not measure any route's peak memory (the formula's values are from another test, as in `lab-backtest-3.md`, section 7). The factor 1.51 is neither confirmed nor shown wrong by this measurement.
- The placeholder side is checked by one route (the book page) at one count; the other seven routes carry at most 30 values and are far from the limit, and the rule's premise (growth in proportion to the rows) has no record between 100,000 and 200,000 rows other than this one (D218).
- The mark at 150,000 rows is **5 times** the capture's largest size. It is not a statement about 300,000 rows.
- The mark's measurement is separate from B and does not decide it.

## 6. B at 90,000 rows: result

**Not yet measured.**

(When B is measured, this section is followed by the records of the measurement, the verdict and the comparison with the naive estimate. The reading in section 4.5 is decided before it. The prediction in section 4.1 is not changed.)

## 7. What the lab cannot say

The limits of the lab are those of `lab-backtest.md` (section 6), `lab-backtest-2.md` (section 7) and `lab-backtest-3.md` (section 7), and are not repeated in full. Those that bear on this round:

- **B at 90,000 rows is a test between two counts already seen and at 3 times the capture's largest size** (section 3). It is easy, and whatever B shows at this count is not a statement about a count never seen or about 300,000 rows. **It does not repair the third round**: B at 300,000 rows stays undecidable.
- **The lab's data is the generator's** (for example how many books a shelf holds, and that every book has the same slug). Whether the 90,000- and 150,000-row databases have properties the generator's earlier databases lacked was not read.
- **Cache behaviour of a real deployment**, data that does not fit in the database's memory, contention with other work on the same machine and the load of writes are not in the lab. The entry writes the `views` table and nothing else and takes GET only.
- **B is measured with the application's time limit lifted and the memory limit at 4G**, inside a container limited to 6 GiB; it says nothing about the application stopping early by time or by memory at the defaults. The mark is where that is looked at, one request per route and without load.
- **The estimate assumes a database whose statistics are in order** and is carried to 90,000 and 150,000 rows from a capture whose largest size is 30,000 rows (3 and 5 times).
- **The lab has not measured the peak memory of any route**; the memory side of the mark uses the formula of the second extrapolation test.
- **How hard the two heavy routes are pushed is not shown by the limit rps.** In the third round's prediction the ZIP export was about 97% of the predicted database time of one request of each route taken together, so the predicted limit rps followed the share of the ZIP export. Whether the 90,000-row prediction has the same shape was not read here; the shares of the book page are expected to show in the utilization at the lower steps and not in the limit rps. This is the shape of a prediction and not a measured value.
- **Two of the three sides of the mark are checked by one hit each**, and the memory side by none at this count (section 5.3).
- **One row count, once.** 90,000 rows and 150,000 rows are counts not measured before this round and are used up by it: neither can be used again as a test of anything repaired afterwards (spec section 7.4.1 applies the same rule).
- **One application.** B is run on BookStack only; the third application (Bagisto) is a later step.

This file does not claim any precision on a real deployment.
