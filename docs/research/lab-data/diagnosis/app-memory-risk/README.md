# The factor of the memory side of the `app_limit_risk` mark

The mark (`docs/simulator-spec.md` section 6.4) says that a route may be cut by the app's own limit before the database is the limit. Its first side (D178, `../app-limit-risk/`) is php's execution time. The second backtest showed a miss that the first side cannot see: at 200,000 rows every request of `GET /books/{slug}` in the lab ran out of php's memory limit (512M) while its execution time was about half of the 30-second limit (`docs/research/lab-backtest-2.md` sections 4.4 and 5.3). This directory holds the one number the memory side needs and how it was worked out. Only records that already existed were read: nothing was measured for it, and no record at 300,000 rows exists to read.

`factor.json` is made by `poc/lab/diagnosis/app_memory_factor.py` (`python3 -I poc/lab/diagnosis/app_memory_factor.py --repo=. --out=docs/research/lab-data/diagnosis/app-memory-risk`); a unit test checks that the file is what the script gives now and that the tool's constant is the file's `factor`.

## What is compared with what

The model's figure is the route's peak memory at the scenario's table size: the fitted formula `a + b x N^k` of section 6.2 (`peak_memory` of the loaded input). The test records say how far a measured peak can be above the value the committed formula gave for it. The records are the second extrapolation test (200,000 rows, both apps; `docs/research/extrapolation-data-2/compare.json`): for each route, the value the formula gave (committed before the measurement) and the peak memory of the cold request.

## The factor

For each route that has both a prediction and a measured peak of at least 100 MB, the measured peak divided by the prediction. The factor is the largest ratio, rounded up to two decimals: **1.51**.

| App | Route | Predicted (bytes) | Measured (bytes) | Ratio |
| --- | --- | --- | --- | --- |
| BookStack | `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 193,638,170 | 291,803,960 | **1.507** |
| BookStack | `DELETE /books/{bookSlug}/page/{pageSlug}` | 193,659,370 | 291,806,536 | 1.507 |
| BookStack | `POST /settings/recycle-bin/empty` | 193,648,746 | 291,787,616 | 1.507 |
| BookStack | `DELETE /books/{bookSlug}/draft/{pageId}` | 193,675,769 | 291,804,672 | 1.507 |
| BookStack | `DELETE /books/{id}` | 193,717,473 | 291,815,784 | 1.506 |
| BookStack | `DELETE /shelves/{slug}` | 193,711,729 | 291,800,736 | 1.506 |
| BookStack | `PUT /books/{slug}` | 307,576,333 | 317,488,440 | 1.032 |
| BookStack | `GET /books/{bookSlug}/export/zip` | 362,234,855 | 373,140,472 | 1.030 |

`factor.json` lists all 29 routes at or above the floor (12 of BookStack, 17 of the verification app; the other 21 ratios are between 0.96 and 1.00) and the 27 routes under it.

**Why a floor of 100 MB.** The measured peaks fall in two groups with nothing between them: routes that stay at the fixed memory of one request (the largest is 50,388,416 bytes) and routes whose memory grows with N (the smallest is 258,020,264 bytes). 100,000,000 is a number inside that gap, fixed before the factor was read from it. The one route under the floor with a large ratio is `DELETE /settings/maintenance/cleanup-images` (6.1 MB predicted, 17.0 MB measured, ratio 2.79): the ratio of two small constants, not a statement about growth, and no memory limit in use is within a factor of 2 of those peaks. With no floor the factor would be 2.80; over only the routes whose formula held within +-30% it would be 1.04. Both are in `factor.json` beside the chosen one.

**Why one factor for every route.** The rule does not switch off for a route whose formula was outside +-30% in a test, or that no test measured. Section 6.2 keeps those routes out of the count N at which a line is reached, and that is unchanged. The mark is the opposite kind of statement (a warning that must not miss), and the records show where a miss would come from: the largest ratios are on routes that were outside +-30% (six routes of the deletion family, 1.507, outside by 34%), and the one route that fell in the lab, the book page, was measured by no test (its 200,000-row requests in the test answered 500 for a different reason, a statement with too many placeholders). So the factor is taken over routes inside and outside +-30% alike, and a route is marked whatever the record of its formula says. The routes that were over-predicted (ratios down to 0.60) are marked more easily than they need to be; that is the direction chosen, as for the execution time.

## The rule

A route is marked when `factor x (the predicted peak memory of one request) >= the memory limit`. The limit is an input of the run (`resources.app_memory_limit`, a size such as 512M); there is no default, and with no limit given the memory side is not made, the output has no key for it, and the output is the same bytes as without it. A route with no peak-memory estimate is not judged and is listed apart with the reason; it is never counted as having no risk. The output says which limit a route was marked for: the memory side is its own block (`app_limit_risk_memory`) beside the execution-time block (`app_limit_risk`), with its own limit and factor. The mark does not change a utilisation or a limit rps.

## Where the lab's peak memory comes from, and one check on it

The lab's own estimate carries no peak-memory formula: it is built with `bin/estimate --axes-only`, which leaves the route-level peak memory `not_given` (`docs/research/lab-data/estimate-50k-200k/200k/estimate.json`). With no figure the memory side marks nothing, and the miss of the second backtest could happen again. So the lab's estimate for the next count takes the route-level peak-memory formulas from the committed BookStack fits of the second extrapolation test (`docs/research/extrapolation-data-2/bookstack/fits.json`, fitted on 10 to 10,000 rows; all eight lab routes have a formula with `fit_status` ok): `bin/predict --fits=<that file> --at=<N>`, then `bin/estimate` with `--predictions` and without `--axes-only`, as the test's own estimate was made. The formulas are not fitted again. Dropping `--axes-only` changes only three fields of each route (`peak_memory`, `queries_route_fit`, `peak_memory_formula`); the queries, the rows and the types are the same bytes (`RowEstimatorFormulasTest::test_axes_only_takes_the_table_and_the_axis_and_none_of_the_quantities_of_the_predictions`), and the database-time prediction reads none of the three.

One check that the test's formula for the book page describes the lab's page and not only the test's: the application's log of the 200,000-row falls says it ran out of memory trying to allocate 97,271,808 bytes. The response body of the same page in the lab at 50,000 rows is 24,329,703 bytes (`../../recheck-mark/mark-50000/http.jsonl`); four times that is 97,318,812, within 0.05% of the failed allocation. The page grows in proportion to N, which is what the formula's exponent (0.99) says. This checks the exponent and says nothing about the constant: how many bytes of peak memory the lab's php-fpm process needs per row was not measured.

## What the rule says on the lab's own records

The lab has requests at 10,000, 30,000, 50,000, 100,000 and 200,000 rows, eight routes each (40 pairs). The formula of each route is the BookStack one of `docs/research/extrapolation-data-2/bookstack/fits.json` (the lab runs the same BookStack; the formulas were fitted on 10 to 10,000 rows of the test's data, not on the lab). The limit is the lab's 512M (536,870,912 bytes). What stopped a request is taken from the records named in the script (`STOPPED`): the book page at 200,000 rows ran out of memory (the application's own log), the ZIP export at 100,000 rows was cut by the execution limit, and the ZIP export at 200,000 rows answered 500 after 52.6 s with a cause that was not recorded (the application's log of that request was not kept).

| | Count |
| --- | --- |
| hit (marked, ran out of memory) | 1 (`GET /books/{slug}`, 200,000 rows: predicted 714.1 MiB, bound 2.11 times the limit) |
| miss (not marked, ran out of memory) | 0 |
| false alarm (marked, did not run out of memory) | 2 |
| correct no-mark | 37 |

The two false alarms are the book page at 100,000 rows (predicted 359.7 MiB; bound 1.06 times the limit; it answered 200) and the ZIP export at 200,000 rows (predicted 345.5 MiB; bound 1.02 times; it was cut, cause not recorded, and its measured peak in the test was 356 MiB, under the limit). Both are close to the line, and the first is a request that the formula puts at 70% of the limit before the booted application's own memory is added.

What this does and does not show.

- The rule was made after the miss at 200,000 rows was known, so the hit on that request is not a test of it. The factor itself comes from other records (the extrapolation test's measured peaks), and any factor of 0.72 or more marks that request. The test of the rule is a count it has not seen.
- The lab's own peak memory was never measured route by route. The lab's estimate carries no peak-memory formula (`peak_memory` is `not_given` in its estimate files), so the figures above are the extrapolation test's formulas read at the lab's counts. Whether the lab's php-fpm process reaches the same peaks as the tool's measured request is not shown by these records.
- The measured peak in the records is `memory_get_peak_usage()` of the request minus the memory in use before the request began (`poc/runtime/src/Run/Requester.php`). The memory of the booted application is in php's limit and not in this figure, and the factor (a ratio of two figures that both leave it out) does not cover it. The size of that part was not measured here.
- The records cannot tell a factor of 1.51 from one near 1.0 by hit or miss alone: one request fell by memory. The factor comes from the ratios.
