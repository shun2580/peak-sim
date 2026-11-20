# Extrapolation test (A), fourth run: do the estimates predict 240,000 rows?

Status: **The result is written** (sections 2 to 6). The prediction was committed at 2026-11-19 17:40:41 (`f52f692`) and has not been changed; the first measurement started at 2026-11-20 06:46:30. Nothing in the rules was changed when the figures were added.

Result in one line (rule B, R1, which gives the verdict; the multiple of the capture's largest size is 8): the verdict of A is given for each application, as in the earlier runs. The verification app **passes** (queries 21 of 21, rows examined 21 of 21 inside the lowest standard). BookStack **passes** (queries 34 of 34, rows examined 29 of 34 = 85.29%; 28 needed, so the margin is **one route**). Neither application is undecidable. Rule A, shown beside the verdict and not deciding, gives BookStack a **fail** (rows examined 26 of 34). Condition 1a of section 0 of the spec is met for both applications (all four numbers short are 0), but it is met thinly on BookStack, and conditions 1b, 2 and 3 are still open (section 6).

This file is the record of the fourth run of test A of `docs/simulator-spec.md` (sections 7.1, 7.4 and 7.4.4). It says in which order things were done, what was measured, and what the numbers can and cannot support. The pass line, the tolerances and the denominator are those of the earlier runs and are not moved by this file. It follows `docs/research/extrapolation-test.md` (first run, 100,000 rows; not rescored), `extrapolation-test-2.md` (second run, 200,000 rows) and `extrapolation-test-3.md` (third run, 300,000 rows). The fifth round of test B, at the same time, is in `docs/research/lab-backtest-5.md` (B at 170,000 rows).

What is checked is a **check against a size reproduced in a test setup**: an estimate compared with a measurement made on generated data. Nothing here is observed on a real deployment, and this file does not claim any precision on one.

**In brief:**

1. A was run at **240,000 rows**, which is 8 times the largest size the statements were captured at (30,000 rows), and a count that appears in no earlier record as a scale (D223).
2. **The verification app passes** (21 of 21 on both quantities) and **BookStack passes under rule B** (queries 34 of 34; rows examined 29 of 34, 28 needed). **Under rule A, BookStack fails** (26 of 34). The two rules differ because six marked routes move inside the line and three move outside (section 4.5).
3. **The margin is thin.** BookStack passes by one route on the lowest standard for rows examined, and by one route on the goal for the unmarked routes (13 of 15, 12 needed). `GET /shelves/{slug}` is outside ±20% by 0.23 point (20.23%).
4. **Ten BookStack routes are outside the goal** (eight marked, two unmarked); the verification app has none. Four of the heavy values that rule B takes appeared in one lap of the repeat only, and their cause was not examined (section 4.6).
5. Two routes could not be measured at all (`GET /books/{slug}`, which answered 500; `POST /books/{bookSlug}/copy`, which ran past 600 seconds). They are counted apart, not as misses (section 4.7).
6. The first BookStack round of the cold measurement was cut by a restart of the machine and is not used; the second round ran alone on a new database. The verification app and the repeat of the marked routes were measured before the restart and are reused (section 3.2).
7. Two readers rebuilt the verdict from the raw records without seeing each other's work and agree (section 4.1).

## 1. What was tested

For each route, three quantities are estimated at N = 240,000 and the same cold request is then measured at N = 240,000.

- **Number of queries:** the sum over the statement types of the route of the count formula of each type.
- **Rows examined:** the sum over the statement types of the per-execution rows (a per-statement fit, H1, with the tangent rule above the capture's largest size) times the count. EXPLAIN gives only the marks and the upper side of the marked statements.
- **Peak memory:** reported; it does not decide the test.

| Quantity | Lowest standard | Goal |
| --- | --- | --- |
| Number of queries | ±5% | ±2% |
| Rows examined | ±20% | ±10% |
| Peak memory (reported, does not decide) | ±30% | ±30% |

Relative error is `|estimate − measured| / measured`; when the measured value is 0, the estimate counts as a match if it is below 0.5. Where a route is outside a line, its multiple (measured ÷ predicted) is also given.

- **Pass:** both quantities at 80% or more of the denominator, inside the lowest standard, and no undecidable condition. **Undecidable:** a denominator of fewer than 5 routes, or more than 20% of the routes that have an estimate not measured.
- **Rule B decides, rule A is shown** (spec 7.1, D212). For an unmarked route the one cold value is compared. For a marked route (one whose plan may change with size) the largest of its four cold values is compared under rule B (one cold value of the cold measurement and three more of the repeat), and the first cold value under rule A.
- **R1 is the reading that gives the verdict** (D212): a route whose estimate is not `complete` is outside the denominator. R2, shown beside it, counts such a route as a route with an estimate.
- **The verdict is given for each application** (D71, D215); the figure for the two together is shown and is not used for the verdict.
- **Targets and data.** The verification app (24 routes) and BookStack (36 routes in the estimate), with the data built for each route's table, as in the earlier runs.
- **The estimate is not the one of the third run.** After the third run (300,000 rows; BookStack failed, 24 of 33) the rule for the estimate was revised (D225: the rules P1 and P2 for the point of a marked statement), and the BookStack statements were captured again on databases built without the application's own rebuild of `references` and with the state-changing routes measured last (D229, D230). Under D153 the count for condition 1a is made again at the next new size after such a change; that is this run. The pass line, the tolerances and the denominator were not changed.

## 2. The prediction and the order that makes the test valid

### 2.1 What was committed, and when

| time | what |
| --- | --- |
| 2026-11-19 17:40:41 | `f52f692`: the estimates and the lists of marked routes of A (four files) were committed, together with the prediction of test B |
| 2026-11-20 06:46:30 | the first measurement of this run (verification app, every route cold once) |
| 2026-11-20 06:47:35 | the repeat of the marked routes of BookStack started |
| 2026-11-20 08:15:32 | first BookStack cold round started (cut at 11:12:03; not used) |
| 2026-11-20 23:31:51 | second BookStack cold round started (used) |

The four files are `poc/lab/predictions/extrapolation-4-{fixtures,bookstack}-{estimate,marked}.json`. They were committed 47,149 seconds (13 hours) before the first measurement and are unchanged since: `git diff` against `f52f692` over them is empty, and the order check (`order_check.py` on the four files and the order record) gives **order: holds**. Every start line of the order record names the tool commit `7a4cf32`, a descendant of `f52f692`. Before the second BookStack round the four files of the tool copy were compared by SHA-256 with the blobs at `f52f692` and were the same (`prediction_sha256_before_redo.txt`); the two readers took the same four blobs from `f52f692` again.

| File | SHA-256 |
| --- | --- |
| `extrapolation-4-fixtures-estimate.json` | `27b5109546920e38008d1224b7288b4377c245800532dd17c720aa073a345335` |
| `extrapolation-4-fixtures-marked.json` | `4b96cfcd034204c1948ebaacef866d1b57d4de72cce8230438c70fcf8290eaef` |
| `extrapolation-4-bookstack-estimate.json` | `8c6f11dc…` (full value: `8c6f11dcf28b2a6ef6c6a3207aa0b53397e0ca3650bb17f4dbd6c29bddda6d10`) |
| `extrapolation-4-bookstack-marked.json` | `63cb2e67…` (full value: `63cb2e67ceef2b44c7cefe5ea907dcc02ead47fc0c19d82ee62edca0c32f7000`) |

### 2.2 What the estimates were made from

- **Capture.** BookStack: the 31 routes and the 12 routes whose empty-system request was `not_found`, captured again at 100, 1,000, 10,000 and 30,000 rows (the 12 also at 10 rows), after ANALYZE, without the application's own rebuild of `references`, and with the routes that change the state of a table measured last in each database (D230). The verification app: the capture of the third run, 24 routes, not taken again because no route of it changes a table that other routes read (D232). **The capture's largest size is 30,000 rows and the test size is 240,000 rows: 8 times.**
- **EXPLAIN** at 240,000 rows, on a throw-away database, with the small side at the tool's default of 10,000 rows. No request other than the build check, ANALYZE and EXPLAIN was sent to a 240,000-row database before the commit.
- **Marked routes.** BookStack **21 of 35** routes in the EXPLAIN; the verification app **0 of 24**. So the repeat of the marked routes was run for BookStack only. The marks are those of test A; the memory part of the mark of the lab (`lab-backtest-3.md`) is a different list.
- **Estimates.** 24 routes for the verification app (22 `complete`, 2 `partial`: `GET /ng/nested`, `POST /ok/bulk`) and 36 routes for BookStack (35 `complete`, 1 not: `POST /books/{bookSlug}/copy`). Each estimate and marked list was made twice and the two outputs were identical byte for byte.

### 2.3 What the tool copy was, and the difference from the capture tools

The measuring tool is a copy at `7a4cf32`. The difference between it and the BookStack capture tool (`6ae5195`) is 0 files in `poc/runtime` and `fixtures`. The difference between it and the verification app's capture tool (`8f4cd65`) is 62 files, which are listed in `prep_diffs.txt` of the records (D233) as a fact. A second reader compared the two commits and found that **the generator (`poc/runtime/src/Data`), the verification app (`fixtures/laravel-app`) and the building of the database differ in 0 files**; the 62 files are on the side of the prediction, the estimate, the laboratory load and the repeat, and in the order in which the state-changing routes are measured (the driver, the profile, the route order and `bin/inner/measure.php`). The verification app was measured without a profile, so there is nothing to reorder and its order of measurement is unchanged. The difference is therefore on the side of the estimate, the prediction, the repeat and the order, and not on the side of the generator, the application or the build.

## 3. Measurement

The records are in `docs/research/extrapolation-data-4/measured-240000/` (committed as `0c8fc47`). Times are the host's clock (JST).

### 3.1 The runs

| Step | Target | Start | End | Exit | Used |
| --- | --- | --- | --- | ---: | --- |
| Cold, once, every route | Verification app | 2026-11-20 06:46:30 | 2026-11-20 08:15:32 | 0 | yes |
| Repeat of the marked routes, 3 laps | BookStack (21 routes × 3 laps = 63 trials) | 2026-11-20 06:47:35 | 2026-11-20 11:08:51 | 0 | yes |
| Cold, once, first round (project `peak-t5a-bs`) | BookStack | 2026-11-20 08:15:32 | cut at 2026-11-20 11:12:03 | 255 | **no** |
| Cold, once, second round (project `peak-t5a-bs2`) | BookStack | 2026-11-20 23:31:51 | 2026-11-21 03:03:18 | 0 | yes |

The verification app has no repeat, because it has no marked route (`variance/fixtures.skipped`).

### 3.2 The cut round, the reuse, and what came between

- **The cut.** The first BookStack cold round was cut at 2026-11-20 11:12:03 (exit 255) by a planned restart of the machine started by Windows Update. The Windows System log (event 1074) shows two restarts: 11:12:02 (`MoUsoCoreWorker.exe`) and 11:14:13 (`TrustedInstaller.exe`). The machine was down until about 22:42. That round had written only `static.json` (45 MB; not kept in the records). It is **not used** for any figure of this file; its record is kept apart in `_interrupted_1112/` with a note, and no data file of it was read for the verdict. Its containers and its database volume were left as they were (no `volume rm`, no `down -v`, no prune) and were not used.
- **The second round.** It started at 23:31:51 in a new project on a new database, built the same way as the capture and the estimate (without the application's own rebuild of `references`; the `references` table has 240,000 rows in it, which an executed rebuild would not leave), and ran **alone**, to 03:03:18.
- **The reuse, said plainly.** The verification app's round and the BookStack repeat were measured **before** the restart, with the same estimates and the same tool (`7a4cf32`), on their own databases, independent of the cut round (they ended with exit 0 at 08:15:32 and 11:08:51). They are used as they are. The restart therefore fell **between** them and the second BookStack round, and the three measures of this run were not taken in one unbroken run of the machine. The records say so (`README.md`, `order_record.txt`). The repeat ran at the same time as the verification app and the first (cut) BookStack round; the databases were separate, so the counts of queries and rows examined are not expected to depend on it. Times were not compared in this test.
- **Cleaning up after the measures.** The tool tore its own stacks down when each run ended, with `compose down -v` on its own project, so **the database volume of each of the three measures used here no longer exists**: the verification app (`bin/measure`, without `--keep-stack`), the second BookStack round (the same), and the repeat (the last step of its script, `bin/stack down`). The volume of the cut first round was not removed. This is stated as a fact; whether it is acceptable is not decided in this file. The three databases can no longer be measured again or looked into.
- **A record that is missing.** The periodic record of the free memory of the host (`memavail.tsv`) does not exist for this run: its script was started without the variable that names the output folder and wrote nothing. The memory was read by hand during the second round (available 10.2 to 11.0 GB). It does not enter any verdict. The peak memory of each request is recorded by the tool itself in `attempts.jsonl`, and no request was stopped for lack of memory: the one verification-app route that failed on its own ended with exit 139 (a segmentation fault, not 137), and the others are timeouts and the statement limit.

### 3.3 What the tool reported, before any comparison

| | Routes tried | Outcome classes |
| --- | ---: | --- |
| Verification app | 24 | ok 22, runner failed 1 (`GET /ng/first-or-create-in-loop`, exit 139), timeout 1 (`POST /ok/bulk`, 600 s) |
| BookStack (second round) | 63 (202 attempts) | ok 20, unauthenticated 21, not found 9, exception 7, validation 422 6 |

The 202 BookStack attempts ended with status 200 (44), 302 (76), 401 (44), 404 (14), 422 (14) and 500 (10).

## 4. Result

The figures are those of the verdict tool of the two readers (section 4.1), which runs the comparison tool `compare_test2.py` (SHA-256 `95da6a9b77bdc5c43a1455c9c1ac9b4f11cb0e966aa1cfeac4e42220e382bb8b`, checked before the run) on the four committed files and the records. The tables below are copied from its output; no figure was rounded again by hand.

### 4.1 How the verdict was made

Two readers rebuilt the verdict from the raw records and the prediction files, each without seeing the other's work, and then compared. One reader ran the committed verdict scripts (`docs/research/lab-data/dryrun-286/judge/`, committed in `53825c8` and `62ba6a2`, **not changed** for this run; they passed their own 32 checks against known answers before use, with none skipped). The other wrote a count of their own from the raw `attempts.jsonl` files and the estimates, and did not use the scripts. They agree on every figure of sections 4.2 to 4.4 and 4.7.

One disagreement came up during the work and was closed by the written rule: the script reader's own first count had **22** routes of the verification app in the denominator and the tool had **21**. The one route was `GET /ng/nested`, which was measured but whose estimate is not `complete`; the tool leaves such a route out of the denominator (R1, section 1), and the count made by hand had not applied that rule. With the rule applied the two counts are equal (21 of 21). No disagreement was left.

The outputs of the verdict (`a-verdict.json`, `a-verdict.md`, the comparison tool's `compare.json` and `compare.md`, and the order check `order.md`) are in `docs/research/extrapolation-data-4/compare/`, with a `README.md` giving the commands. They are byte copies of what the script wrote; their SHA-256 are: `706560cee169bb54283aaa4b800440ca35c19fc4b24a6f58032c646cec603f10` (`a-verdict.json`), `c74b0f3bd5123bfb7dc37b6ba8bd897794c3057b280723018240d7268e559b60` (`a-verdict.md`), `a461334978cb5224fd9c3c4c20a46d2393c7dddd99362f11c4786cf16eaed93f` (`compare.json`), `20575ab70a0cc41b0eea28af6d54b864d2794c82bb562e5493396f17b573352a` (`compare.md`) and `d4152385c209e69c4a6b8d245bd4fced3f1d880df1a0e5d23abcc89060807784` (`order.md`).

Records read: the first cold values of the verification app (`fixtures/attempts.jsonl`; 24 routes, 100 records); the second cold round of BookStack (`bookstack/attempts.jsonl`; attempt 2, class ok, is the cold value); the repeat (`variance/bookstack/`; 63 `attempt.jsonl`). Not read: `_interrupted_1112/`.

### 4.2 Verdict

| Scope | Rule B (decides), R1 | Rule A (shown), R1 | Rule B, R2 | Rule A, R2 |
| --- | --- | --- | --- | --- |
| Verification app | **pass** | pass | pass | not recounted |
| BookStack | **pass** | **fail** | pass | not recounted |
| Both applications together (shown; not used for the verdict) | pass | pass | | |

Rule B, R1, with the counts that decide:

- Verification app: queries 21 of 21 inside ±5% (enough, 80% needed); rows examined 21 of 21 inside ±20% (enough).
- BookStack: queries 34 of 34 inside ±5% (enough); rows examined **29 of 34** inside ±20% (85.29%; **28 needed, so one route over the line**).
- Both together: queries 55 of 55; rows examined 50 of 55 (90.91%). Shown only (D71, D215); a pass of the pooled figure is not a pass of A.

Rule A, shown: the verification app passes (21 of 21, 21 of 21); BookStack **fails** on rows examined (26 of 34 = 76.47%, 28 needed) and passes on queries (34 of 34). The two rules differ on BookStack alone, and for the reason in section 4.5. R2 under rule B gives the same verdicts as R1 for both applications: the same shares for BookStack, and for the verification app 22 of 22 on both quantities (`GET /ng/nested` comes inside the denominator and is 0.00% off on queries and rows examined). R2 under rule A was not recounted.

**Undecidable: no, for both applications.** Verification app: 24 routes, 22 with a complete estimate, 21 in the denominator, 1 with an estimate not measured (1 of 22 = 4.5%). BookStack: 36 routes, 35 with a complete estimate, 34 in the denominator, 1 with an estimate not measured (1 of 35 = 2.9%). Both are well below 20% and every denominator is far above 5 routes. The grounds are the numbers above and the rule of section 1; the verdict is not "not undecidable by default".

### 4.3 Share within tolerance, by group (rule B, R1)

| Scope | Group | Quantity | Denominator | Inside lowest standard | Inside goal |
| --- | --- | --- | ---: | ---: | ---: |
| Verification app | all | queries | 21 | 21 (100.00%) | 21 (100.00%) |
| Verification app | all | rows examined | 21 | 21 (100.00%) | 21 (100.00%) |
| BookStack | all | queries | 34 | 34 (100.00%) | 33 (97.06%) |
| BookStack | all | rows examined | 34 | **29 (85.29%)** | 24 (70.59%) |
| BookStack | unmarked | queries | 15 | 15 (100.00%) | 15 (100.00%) |
| BookStack | unmarked | rows examined | 15 | 15 (100.00%) | **13 (86.67%)** |
| BookStack | marked | queries | 19 | 19 (100.00%) | 18 (94.74%) |
| BookStack | marked | rows examined | 19 | 14 (73.68%) | 11 (57.89%) |
| BookStack | all | peak memory (reported only) | 34 | 22 (64.71%) | 22 (64.71%) |
| Verification app | all | peak memory (reported only) | 22 | 22 (100.00%) | 22 (100.00%) |

The verification app has no marked route. The 36 routes of BookStack in the estimate are 15 unmarked routes and 19 marked routes in the denominator; the other two are the two routes counted apart in section 4.7, both marked. Of the 21 marked routes, 19 are in the denominator.

**What version 1.0 asks of A (spec section 0, condition 1a), in these figures, for each application:** unmarked routes inside the goal: verification app queries 21 of 21 and rows examined 21 of 21 (17 needed); BookStack queries 15 of 15 and rows examined **13 of 15** (12 needed). All routes inside the lowest standard: verification app 21 of 21 and 21 of 21; BookStack queries 34 of 34 and rows examined **29 of 34** (28 needed).

### 4.4 Rule A and rule B side by side (BookStack, rows examined, lowest standard)

Rule A takes the first cold value of a marked route; rule B takes the largest of its four. On BookStack:

- Six marked routes are **outside under rule A and inside under rule B**: `DELETE /books/{bookSlug}/chapter/{chapterSlug}`, `DELETE /books/{bookSlug}/draft/{pageId}`, `DELETE /books/{bookSlug}/page/{pageSlug}`, `DELETE /books/{id}`, `DELETE /shelves/{slug}` and `POST /settings/recycle-bin/empty`. The first cold value of each is 1,248,4xx to 1,428,5xx; the largest of the four, 1,860,5xx to 2,040,4xx, is near the estimate (about 2,157,5xx).
- Three marked routes are **inside under rule A and outside under rule B**: `GET /books/{slug}/references`, `GET /shelves/{slug}/references` and `PUT /shelves/{slug}`. Each had one lap about 1.8 to 3.9 times the other three (section 4.6).
- So: rule A 26 of 34; 26 + 6 − 3 = **29** under rule B. On the goal for rows examined, all routes: rule A 23 of 34 and rule B 24 of 34; the unmarked routes are the same under both (13 of 15).

### 4.5 Routes outside the goal (condition 3)

Routes with a quantity (queries or rows examined) outside the goal under rule B: **verification app 0; BookStack 10** (8 marked, 2 unmarked). The multiple is measured ÷ predicted; for a marked route the measured value is the largest of its four cold values (rule B). `GET /shelves/{slug}` is outside on two quantities and is one route.

| Route | Marked | Quantity | Predicted | Measured | Multiple | Relative error |
| --- | --- | --- | ---: | ---: | ---: | ---: |
| `GET /shelves/{slug}/references` | yes | rows examined | 84,217 | 324,204 | 3.850 | 74.02% |
| `GET /books/{bookSlug}/page/{pageSlug}` | yes | rows examined | 1,608,578 | 1,008,237 | 0.627 | 59.54% |
| `PUT /shelves/{slug}` | yes | rows examined | 276,268 | 504,226 | 1.825 | 45.21% |
| `GET /books/{slug}/references` | yes | rows examined | 84,217 | 168,207 | 1.997 | 49.93% |
| `GET /shelves/{slug}` | yes | rows examined | 283.75 | 236 | 0.832 | 20.23% |
| `GET /shelves/{slug}` | yes | queries | 18.878 | 18 | 0.953 | 4.88% |
| `DELETE /books/{id}` | yes | rows examined | 2,157,529 | 1,860,501 | 0.862 | 15.96% |
| `POST /books/{bookSlug}/draft/{pageId}` | no | rows examined | 647,651 | 768,544 | 1.187 | 15.73% |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | yes | rows examined | 275,590 | 324,245 | 1.176 | 15.01% |
| `POST /settings/maintenance/regenerate-references` | yes | rows examined | 316,828,223 | 370,678,285 | 1.170 | 14.53% |
| `DELETE /settings/maintenance/cleanup-images` | no | rows examined | 27,989,601 | 32,280,434 | 1.153 | 13.29% |

- **Outside the lowest standard (±20% on rows examined): five routes**, which are the five that make 29 of 34: `GET /shelves/{slug}/references`, `GET /books/{bookSlug}/page/{pageSlug}`, `PUT /shelves/{slug}`, `GET /books/{slug}/references`, and `GET /shelves/{slug}` (20.23%, just outside). All five are marked. Queries: none is outside ±5% (34 of 34); one route is outside ±2% (`GET /shelves/{slug}`, 4.88%, which is inside ±5% by 0.12 point).
- **Outside the goal and inside the lowest standard: five routes** (the rest of the table), three marked and two unmarked (`POST /books/{bookSlug}/draft/{pageId}` 15.73% and `DELETE /settings/maintenance/cleanup-images` 13.29%, both inside ±20% and outside ±10%).
- **Verification app.** All 21 routes in the denominator have a multiple of 1.000 on queries. On rows examined 19 have the estimate exactly; the only differences are `GET /ok/chunk` and `GET /ok/lazy` (predicted 283,084,039; measured 288,362,402; multiple 1.019, relative error 1.83%, inside ±10%).
- The marks above are those of test A (the explain-based marks), not the lab's mark. For none of the ten routes was a cause examined in this test; for condition 3 all ten are counted as causes not identified.

### 4.6 What is thin, and what was not looked into

- **The margin on BookStack is one route** at the lowest standard for rows examined (29 of 34; 28 needed) and one route at the goal for the unmarked routes (13 of 15; 12 needed). One more route over the line would have failed the lowest standard under rule B. Under rule A it does fail (26 of 34). The pass is real by the rule and should not be read as a wide one.
- **`GET /shelves/{slug}` is on the line.** Its rows examined are 20.23% off (the estimate is 283.75 rows against 236 measured: 47.75 rows of difference), outside ±20% by 0.23 point; its queries are 4.88% off, inside ±5% by 0.12 point.
- **Four heavy values of rule B came from one lap each.** The values that put `GET /books/{slug}/references` (168,207), `GET /shelves/{slug}/references` (324,204), `PUT /shelves/{slug}` (504,226) and `PUT …/revisions/{revId}/restore` (324,245) where they are appear in only one of the four cold values of each route, the third lap of the repeat; the other three cold values of each are 84,210, 84,210 and 84,210 (for the two `references` routes), 276,232 (for `PUT /shelves/{slug}`) and 276,246 (for the restore route). Rule B takes the largest of the four, as the rule says, and it moves three of these routes outside ±20% and the fourth outside ±10%. **The cause of the heavy lap was not examined in this test.** Three of them (`GET /books/{slug}/references`, `GET /shelves/{slug}/references`, `PUT /shelves/{slug}`) are inside ±20% on each of the other three cold values, which is how rule A, reading the first cold value, sees them.
- **The measured value at 240,000 rows, for the routes at the line, is one cold value or the largest of four.** The unmarked routes were measured once. No larger number of repeats stands behind the routes near a line.
- **`GET /books/{bookSlug}/page/{pageSlug}`** is over-estimated (multiple 0.627), the other direction from the four above. Its four cold values are equal (1,008,237) in the measure and the three laps; the estimate is 1,608,578. The cause was not looked into here.

### 4.7 Routes counted apart

Nothing is dropped silently. These routes are in no denominator and are not misses.

- **Verification app.**
  - `GET /ng/first-or-create-in-loop`: the estimate is complete; no cold value, because the measuring tool's child process ended with exit 139 (a segmentation fault; class `runner_failed`). The third run's record shows the same route ending in the same way. It is the one verification-app route that is "estimated but not measured" under R1 (1 of 22).
  - `GET /ng/nested`: measured ok, but its estimate is `partial` (it was cut at the capture's request limit at 30,000 rows), so it has **no estimate** and is outside the denominator under R1. Under R2 it is inside and is 0.00% off on both quantities.
  - `POST /ok/bulk`: its estimate is `partial` (the capture answered HTTP 500 with the placeholder limit at 30,000 rows) and the request ran past the **600-second** limit (class timeout), so it has no estimate and no cold value.
- **BookStack.**
  - `GET /books/{slug}` (marked): HTTP 500 in all four tries with SQLSTATE 1390 (too many placeholders in one statement), so there is no cold value for any quantity. Its estimate is complete (rows examined 3,071,114). Not measured (1 of 35 = 2.9%). The same route was taken out of the mix of B at 170,000 rows for the same reason (D232).
  - `POST /books/{bookSlug}/copy` (marked): no estimate (it is not `complete`) and all four tries ran past 600 seconds (the repeat's three tries took 610 to 626 s). The tool records them under class `exception` with the detail "the request ran longer than 600 seconds", and the comparison counts them as timeouts by that detail. Under rule B a marked route with a timeout is "not measured"; the tool puts the route under "no estimate" first. Either way it is outside the denominator.
  - **27 routes without an estimate row** (the API routes answering 401, and routes answering 500 or 422 on an empty system) were measured and are outside the test, as in the earlier runs. They are in no count.

## 5. Against the third run

Read as a comparison for reading; the verdict of this run is decided by this run's figures alone. The rule for the estimate was revised after the third run (D225), the capture was taken again, and the size is not the same (240,000 against 300,000; 8 against 10 times the capture), so the counts of the third run are not recounted here; this is a new count (D153).

| | Third run (300,000 rows; 10×) | This run (240,000 rows; 8×) |
| --- | --- | --- |
| Verification app, rule B | pass (21 of 21, 21 of 21) | pass (21 of 21, 21 of 21) |
| BookStack, rule B, queries inside ±5% | 32 of 33 | 34 of 34 |
| BookStack, rule B, rows examined inside ±20% | 24 of 33 (72.73%; 27 needed; **fail**) | 29 of 34 (85.29%; 28 needed; **pass**) |
| BookStack, unmarked, rows examined inside ±10% | 11 of 13 | 13 of 15 |
| BookStack, routes outside the goal | 18 (16 marked, 2 unmarked) | 10 (8 marked, 2 unmarked) |
| Routes not measured, with an estimate | 3 (`GET /ng/first-or-create-in-loop`, `GET /books/{slug}`, `PUT /books/{slug}`) | 2 (`GET /ng/first-or-create-in-loop`, `GET /books/{slug}`) |

The BookStack margin of this run (one route) is smaller than the gap it closed (three routes short in the third run). Whether the change of the estimate or the change of the size made the difference is **not** separated by this comparison: the two changed together.

## 6. What is left of conditions 1 to 3 of section 0 of the spec

Counted as D153 counts them (with the change of D167 and the counting of each application, D215), rule B, R1. The count is made after the result was seen; the rules, the pass line and the verdicts above are not changed by it.

- **Condition 1a (the extrapolation test), for each application.** The number short is `max(0, ⌈0.8 × denominator⌉ − inside the line)`; goal for the unmarked routes, lowest standard for all routes.

  | Scope | Goal, unmarked, queries (±2%) | Goal, unmarked, rows examined (±10%) | Lowest standard, all, queries (±5%) | Lowest standard, all, rows examined (±20%) |
  | --- | --- | --- | --- | --- |
  | Verification app | 21 of 21; 17 needed; short 0 | 21 of 21; 17 needed; short 0 | 21 of 21; 17 needed; short 0 | 21 of 21; 17 needed; short 0 |
  | BookStack | 15 of 15; 12 needed; short 0 | 13 of 15; 12 needed; short 0 | 34 of 34; 28 needed; short 0 | 29 of 34; 28 needed; short 0 |

  **All eight numbers are 0: condition 1a is met for both applications at this size.** The spare is **one route** for BookStack on two of the four (rows examined, goal on unmarked routes: 13 against 12; rows examined, lowest standard: 29 against 28). 240,000 rows is a size that the work behind the revised estimate had not seen (D223), and it is now used up as a test.
- **Condition 1b (the lab backtest), for each application.** Not met by the latest B: B at 170,000 rows (5.667 times the capture; a count not seen before) passed at the lowest standard and not at the goal, item 4 at ±10% being 1 of 5 scenarios (4 needed; **3 short**; D234, `lab-backtest-5.md`). For this run the multiple of the capture's largest size is 8 and 240,000 appears in no earlier record as a scale, but a test A does not count for 1b. B is measured on BookStack only; the verification app has no B.
- **Condition 2 (a third application, Bagisto):** not measured. It is not 0.
- **Condition 3 (the remaining misses; provisional until conditions 1 and 2 are met).** Routes with a quantity outside the goal under rule B: the verification app **0**, BookStack **10** (8 marked, 2 unmarked, section 4.5). No cause was identified in this test, so all ten are counted as causes not identified: the remaining number is **10 (provisional)**. The routes not measured are counted apart and are not misses: with an estimate, `GET /ng/first-or-create-in-loop` and `GET /books/{slug}`; without a complete estimate, `GET /ng/nested`, `POST /ok/bulk` and `POST /books/{bookSlug}/copy`.

## 7. What can be said, and what cannot

What the test is:

- It checks whether the per-statement fit (H1) with the rules of this round, made from small-size captures on analyzed databases, predicts the number of queries, the rows examined and the peak memory of a cold request at 240,000 rows. The estimates and the lists of marked routes were committed before the measurement they are compared with (section 2).
- **The capture's largest size is 30,000 rows and the test size is 240,000 rows: 8 times.** Agreement or disagreement says nothing about a size beyond 240,000 rows or between the sizes measured before.

What it does not support:

- **Two applications only.** The small verification app written for this project, and BookStack with a data profile written for the test. Nothing here is a statement about other Laravel applications; the third application (Bagisto) is a later step.
- **Generated data.** The generator's text bodies hold **no links to each other**, so the application's own rebuild of `references` would leave that table empty at every row count; for that reason the rebuild was run on no database of this round (maintainer's ruling of 2026-11-19 12:10; the 240,000-row database has 240,000 rows in `references` as built). The tables that other routes read are therefore as the generator wrote them, and not in the state a real deployment's own maintenance would leave. Skew, hot rows and the cache behaviour of a real system are not reproduced either. The estimate assumes the database it was captured on and the one it is used on are in the same maintenance state; here both are, because the rebuild was run on neither.
- **A single size, once.** 240,000 is used up by this run. It cannot be used again as a test of anything repaired afterwards (spec 7.5).
- **Few repeats.** Unmarked routes are measured once; marked routes four times, three of them on one database. The four values are not an estimate of the full spread, and a route near a line could fall on either side on another run (section 4.6).
- **Rule B takes the largest of four values for a marked route.** That is the rule (D107, D122) and it is stricter on a marked route that is light on most runs. Rule A is shown beside it for that reason, and gives the other verdict for BookStack.
- **The measurement was not taken in one run of the machine** (section 3.2): one round was cut by a restart and set aside, and the reused measures were taken before it and independent of it. The database volumes of the three measures used no longer exist, and the periodic memory record is missing. Nothing in the records shows an effect on the counts of queries or rows examined; these are written here so that they are not forgotten.
- **Peak memory is reported and does not decide the test:** the verification app 22 of 22 inside ±30%; BookStack 22 of 34 (unmarked 14 of 15, marked 8 of 19).
- **The denominator is what could be estimated and measured.** The routes counted apart in section 4.7 are out of it.
- **The check of rows examined against the server's own count** was made on the verification app only in the first run; it is not repeated here.
- **The test does not show why** the estimate is higher or lower on a route (section 4.6).

This file does not claim any precision on a real deployment.
