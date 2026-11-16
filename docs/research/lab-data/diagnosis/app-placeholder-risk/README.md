# The placeholder side of the `app_limit_risk` mark

The mark (`docs/simulator-spec.md` section 6.4) says a route may be cut by a limit of the application's own before the database is the limit. Two sides existed: the execution time (`../app-limit-risk/`) and the memory (`../app-memory-risk/`). This directory is the third: **a route that sends the database one statement with more bound values than the database accepts** (MySQL: 65,535 in one prepared statement, `SQLSTATE[HY000]: General error: 1390`). The book page failed this way at 300,000 rows in every window of the third lab backtest (`../../../lab-backtest-3.md` section 4.4) and in all four cold tries of the third extrapolation test; the verification app's bulk insert failed this way at 30,000 rows (`../../../extrapolation-test-3.md`).

`rule.json` is made by `poc/lab/diagnosis/app_placeholder_rule.py` from captures that already existed. Nothing was measured for it, no lab database or container was touched, and no record at a count of rows other than the ones named below was read. `python3 -m unittest poc/lab/diagnosis/test_app_placeholder_rule.py` checks that the cells of `rule.json` are what the rule gives on its own statements.

## Where the number of values is kept

A capture (`bin/capture`, `poc/runtime/CAPTURE_FORMAT.md`) keeps, for each statement type, one representative statement: the text of the largest scale that read the most rows, with its bindings. The number of values it was sent with is the length of those bindings. The capture keeps that at **one scale only** (its largest), so a growth with the table size can not be fitted from one capture; it is read from two captures made at different scales where the records have them, and otherwise taken as an assumption (below).

The captures are large and kept outside the repository; they are named by SHA-256 in `rule.json` and the script stops when a file is not the one named.

| capture | SHA-256 (first 16) | scales | used for |
| --- | --- | --- | --- |
| BookStack, the one the 300,000-row estimate read | `69f08fcad32bdd31` | 100, 1,000, 10,000, 30,000 | the rule |
| BookStack, an earlier capture | `8bf76bbed94393410ec1d695d97478f7cf85011718ae7c0c9b90fed1420e9ce5` (full) | 100, 1,000, 10,000 | checking the rule only |
| verification app | `37a8dcd9383a1573` | 100, 1,000, 10,000, 30,000 | the rule, and the bulk insert |

## The rule

For a statement type, `values` is the number of values of its representative statement, `scale` the scale it was taken at, and `fixed` the part of the values that no list holds: the `?` of the statement's own text outside `in (?, ?, ...)` lists (the text, not the type's pattern: a literal such as `limit 20` is a `?` in the pattern and no value in the statement), and none for an `insert ... values` (the rows are the list). At the table size `n` the statement carries

`fixed + ceil((values - fixed) x n / scale)`

values, and **a route is marked when its largest statement carries more than the limit** (a statement with exactly 65,535 is accepted). The limit is an input (`resources.db_placeholder_limit`); the tool has no default and does not know the database.

The assumption: *what a list holds grows in proportion to the table size*, because the ids in it are those of rows of the table that grows (a book's chapters and pages). It is applied to every list, including a list of one id (the book's own id), so it leans to marking too much: a statement that is refused without a mark is the failure, a mark that was not needed is counted and shown.

## What the records say

**The book page's statement** (`select * from activities where ((loggable_type = ? and loggable_id in (?)) or (...) or (...)) order by created_at desc limit 20 offset 0`, the statement the application's log names at the failure): 6,004 values in the capture at 10,000 rows and 18,004 at 30,000 rows, which is a line `4 + 0.6 N`. The lab's data is made with the type of an entity set by `MOD(id, 5)` (1 book, 2 chapter, 0 shelf, 3 and 4 page, all chapters and pages in book 1: `poc/runtime/profiles/bookstack.php`), so the statement lists the book (1 id), every chapter (N/5 ids) and every page (2N/5 ids), and 3 values for the three types: 0.6 N + 4. The application's log at 300,000 rows (`../ladder-300k/logs/`) lists the chapters as 2, 7, 12, ... in steps of 5, as that layout says. The rule made from the capture at 30,000 rows alone gives 6,004 at 10,000 rows, the value seen.

**The verification app's bulk insert** (`POST /ok/bulk`, an `insert` of `orders` with five values a row): 5,000 values in the capture at 1,000 rows and 50,000 at 10,000 rows. The route's capture at 30,000 rows is the one that answered HTTP 500 with error 1390; the rule made from 10,000 rows gives 150,000 values at 30,000.

The rule against what the records say happened, one cell for each count of rows (`rule.json`, `judged_on_the_lab_records`):

| app, route | rows | values the rule gives (largest statement) | marked | what the records say | verdict |
| --- | --- | --- | --- | --- | --- |
| BookStack, book page | 10,000 | 6,004 | no | HTTP 200 (`../per-request/README.md`) | correct no-mark |
| BookStack, book page | 30,000 | 18,004 | no | HTTP 200 (`../per-request/README.md`) | correct no-mark |
| BookStack, book page | 50,000 | 30,005 | no | no HTTP 500 in r-1 and r-2 (`../../../lab-backtest-3.md` section 7, about the second round of `../../../lab-backtest-2.md`) | correct no-mark |
| BookStack, book page | 100,000 | 60,007 | no | HTTP 200 (`../per-request/README.md`) | correct no-mark |
| BookStack, book page | 200,000 | 120,010 | **yes** | four cold tries of HTTP 500 with error 1390 (`../../../extrapolation-test-2.md`) | **hit** |
| BookStack, book page | 300,000 | 180,013 | **yes** | HTTP 500 with error 1390 in every recorded window (`../../../lab-backtest-3.md` section 4.4) and in four cold tries (`../../../extrapolation-test-3.md`) | **hit** |
| verification app, bulk insert | 1,000 | 5,000 | no | captured | correct no-mark |
| verification app, bulk insert | 10,000 | 50,000 | no | captured | correct no-mark |
| verification app, bulk insert | 30,000 | 150,000 | **yes** | HTTP 500 with error 1390 (`../../../extrapolation-test-3.md`) | **hit** |

Hits 3, misses 0, false alarms 0, correct no-marks 6.

The book page at 300,000 rows is therefore picked: 180,013 values against 65,535 (274.7% of the limit). The rule's 180,013 is a little above the 180,004 the line gives, because the book's own id (one id) is scaled with the rest.

**Other routes picked: none.** The captures hold 76 statement types with a representative statement for the eight BookStack routes and 43 for the verification app's 24 routes. Apart from the two statements above, the largest representative has 18 values (BookStack) and 4 (verification app); at 300,000 rows the rule gives at most 60 and 40. No route other than the book page and the bulk insert is marked at any count in the table, and none of the records shows error 1390 on another route (`../../../lab-backtest-3.md` section 4.4: 0 answers of 500 on routes other than the book page).

## What this does not show

- The growth is shown from two captures for one statement of one app and for one statement of the other. For every other statement the rule's growth in proportion to the table size is an assumption, not a record; those statements are far from the limit under it, so the assumption does not decide any mark here, but a statement of another app whose list grows faster than the table (or one that has a large list at a small table) is not covered.
- The lab's own 200,000-row run of the book page (`../../../lab-backtest-2.md`) was attributed to the memory limit and its record has no line of error 1390. The rule marks that count, as the third extrapolation test's four cold tries (error 1390 at 200,000 rows) show it should; the two do not contradict each other, because a request that runs out of memory before it sends the statement never reaches the limit, but the lab's record can not tell which came first.
- The count at which the book page's statement reaches 65,535 values is between 100,000 rows (60,007 by the rule) and 200,000 (120,010); the rule gives about 109,000. No record lies between them, so it is not tested there.
- It says nothing about the memory a statement with that many values needs, or about a limit of the database other than the number of values in a prepared statement.

## Files

| File | What it is |
| --- | --- |
| `rule.json` | the figures of every statement type of the three captures (`app`, `route`, `id`, `scale`, `values`, `fixed`), the two growth checks, and the cells above |
| `../../../../../poc/lab/diagnosis/app_placeholder_rule.py` | makes `rule.json` from the captures (`--check` tells whether the committed file is what the script gives now) |
| `../../../../../poc/lab/diagnosis/test_app_placeholder_rule.py` | its tests, including that the cells are what the rule gives |
