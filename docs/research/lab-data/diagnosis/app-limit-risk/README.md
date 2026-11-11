# The factor of the `app_limit_risk` mark

The mark (`docs/simulator-spec.md` section 6.4) says that a route may be cut by the app's own execution limit before the database is the limit. This directory holds the one number the mark needs and how it was worked out. Only records that already existed were used: the per-request records at 10,000, 30,000 and 100,000 rows (`../per-request/`) and the ZIP route's uncut record (`../zip-limit/`). Nothing was measured for it, and no record at another count of rows was read.

`factor.json` is made by `poc/lab/diagnosis/app_limit_factor.py` (`python3 -I poc/lab/diagnosis/app_limit_factor.py --repo=. --out=docs/research/lab-data/diagnosis/app-limit-risk`); a unit test checks that the file is what the script gives and that the tool's constant is the file's `factor`.

## What is compared with what

php's execution limit counts the CPU time of the php process, not the clock and not the time spent waiting for the database (`../zip-limit/README.md`: a 4-second wait was not counted, 6 seconds of work was cut at 2 seconds of limit; the ZIP route at 100,000 rows was cut after 53 s of elapsed time and about 30 s of php CPU time). The model has no php CPU time: it has the database time of one request (`c_q × statements + c_row × rows`, the calibration's conversion, which is meant to be the database's CPU time). The records give how far apart php's CPU time and the database's CPU time are.

## The factor

For each record whose database CPU reading was not withheld (neither under the measurement floor nor scattered), the php CPU time of one request divided by the database CPU time measured for the same request:

| Rows | Route | php CPU time (s) | Database CPU time (s) | Ratio |
| --- | --- | --- | --- | --- |
| 10,000 | `/books/{bookSlug}/export/zip` | 4.076 | 3.299 | 1.236 |
| 10,000 | `/books/{slug}` | 0.935 | 0.103 | **9.107** |
| 30,000 | `/books/{bookSlug}/export/zip` | 12.098 | 9.937 | 1.217 |
| 30,000 | `/books/{slug}` | 2.617 | 0.342 | 7.653 |
| 100,000 | `/books/{bookSlug}/export/zip` | 40.859 | 33.431 | 1.222 |
| 100,000 | `/books/{slug}` | 8.698 | 1.129 | 7.702 |
| 100,000 | `/shelves/{slug}` | 0.027 | 0.008 | 3.364 |

For the ZIP route at 100,000 rows the cut request (php CPU time 30.4 s) is capped by the limit, so the uncut request of the same route (both times) stands in for it. The factor is the largest ratio, rounded up to two decimals: **9.11**. The records of the routes that cost almost nothing (their database CPU reading is below the floor, so no ratio can be made on them) are not used; their php CPU time is 0.01 to 0.03 s, far from any limit.

**Why the measured database time and not a time converted from counts.** The calibration's conversion of statements and rows into a time reads high for the books page (at 100,000 rows the conversion from its measured counts is 2.47 s against 1.13 s measured), and it is being corrected. A factor taken against the converted time would hold that bias: it would come out near 5.65, and once the conversion is corrected (the model's time for the books page falls to about the measured 1.13 s) 5.65 × 1.13 = 6.4 s would stand under the 8.7 s of php CPU time that was measured. Taking the measured database time keeps the factor right for a conversion that is right.

The ratio is high for a route that reads many rows (the books page, 7.7 to 9.1) and low for one that runs many statements (the ZIP route, about 1.2), so one factor taken at the largest leans towards marking too much and not too little. That is the direction chosen: a route that is cut without a mark is the failure, a mark that was not needed is counted and shown.

## The rule

A route is marked when `factor × (the database time the model gives for one request, the larger of the point and the safe side) ≥ the limit`. The limit is an input of the run (`resources.app_cpu_time_limit`, seconds); there is no default, and with no limit given the mark is not made, the output has no key for it, and the output is the same bytes as without the mark (it does not default to "not at risk"). The mark does not change a utilisation or a limit rps; where a route is marked the limit rps counts it as a request that runs to the end, and the output says so.

## What the rule says on the records themselves

With the limit the lab had (30 s) and the estimate as it stood (point and safe side of the committed estimates, before the estimate's and the calibration's corrections), over the 24 pairs of count and route: 1 hit (the ZIP route at 100,000 rows, which was cut), 0 misses, 3 false alarms (the ZIP route at 10,000 and at 30,000 rows and the books page at 100,000 rows: marked, not cut), 20 correct no-marks. Taking the factor from the 10,000 and 30,000 rows records alone gives the same 9.11, and with it the ZIP route at 100,000 rows is marked.

What this does not show. The records cannot tell a factor of 1.3 from one of 9.11 by cut or not cut alone: only one request was ever cut, and every factor above 1.0 marks it. The factor comes from the ratios, not from the cut. The false alarms are the price of one factor for both kinds of route.
