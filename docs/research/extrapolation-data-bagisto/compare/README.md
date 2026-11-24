# Verdict outputs of test A on Bagisto at 240,000 rows

What the verdict scripts wrote when they were run on the records in `../measured-240000/` (the every-route measurement and the third repeat) and on the two prediction files `poc/lab/predictions/extrapolation-bagisto-a-{estimate,marked}.json` (committed at `207911e`; unchanged since: `git diff 207911e HEAD -- poc/lab/predictions/` is empty). The verdict and its reading are in `docs/research/extrapolation-test-bagisto.md`.

## Two builders, who did not look at each other

The verdict was built twice from the raw records, by two builders who worked in separate directories and did not open each other's directory or exchange numbers until both were done. The first builder's outputs are the files here; the second builder's outputs are not copied, only their SHA-256 are compared below.

- First builder: ran 2026-11-25 06:21:39 to 06:25:06 (JST). Opened the other directory at 06:27:13, after the last time it had written down.
- Second builder: ran 06:22:40 to 06:26:00. Its notes say that the first builder's directory was not opened.
- Disclosure: before rebuilding, the first builder had read the progress notes of the run (the outcome classes of some routes and the peak PHP memory of the marked routes), not the comparison of estimates with measured values.

**The two sets of outputs agree on every item.** These files are byte-identical (SHA-256 shown in full):

| File | SHA-256 |
| --- | --- |
| `judge_a_rule_a/a-verdict.json` (and `judge_b_reference/a-verdict.json`) | `4cc77ed01d78ecfb7c38819bbf7576d0ca2ac9f8b34ef74cde59650089f3666d` |
| `listed_rule_a/count-listed.json` (and `listed_b_reference/count-listed.json`) | `08ed6e0d0ba2752115cfa015ea5d5710d7a5474eab33e0413f72258e1f502013` |
| `listed_rule_a/judge/a-verdict.json` | `d009cf46c71c29bd7251657b429bba7c3ea765d15e06fb06da17612e38240b0e` |
| `ts/timeout-trials.tsv` | `c3b1c7ece0437e90a2971420bcca4aacc26bb7c913837ebabbdd6a2a1c4d6596` |
| `cf/cold-fail-marked.tsv` | `7a8f33475f0ed548e04c38e1846f4f1a23af02d44b6c1cb974a500949e678805` |

The `R1/compare.json` files differ between the two builders only in the characters of the output path they contain. The R2 numbers were built in two different ways (the first builder ran a copy of the comparison tool, the second built tables from the scripts' outputs) and give the same numbers. The offset counts and the numbers of condition 3 agree in numbers and route names. The hash of the 120 repeat records was first made in a different way by each builder (the second also hashed `trial.json`); the first builder's hash of the same set made the second builder's way is `561e3459…1748`, equal to the second builder's.

## What was run, in order

| Step | Script (SHA-256 prefix) | Output |
| --- | --- | --- |
| 1. Timeout scan (D255) of the every-route measurement and the third repeat | `docs/research/extrapolation-data-bagisto/prediction/arrived-list/timeout_scan.py` (`4927e6a5…`), self-test 6 of 6 | `ts/` |
| 2. Cold-fail rewrite of the marked routes (D256) | `…/cold_fail_marked.py` (`da668a11…`), with `docs/research/extrapolation-data-2/compare_test2.py` (`95da6a9b…`) and the marked file | `cf/` |
| 3. Verdict, 57 routes with a complete estimate (rule B decides, rule A shown) | `docs/research/lab-data/dryrun-286/judge/judge_a.py` (`48eed964…`) | `judge_a_rule_a/` (records of step 2, "(a)"), `judge_b_reference/` (records of step 1 only, "(b)", for reference) |
| 4. Count of the routes that arrived, 19 routes (D250, D252) | `…/arrived-list/count_listed.py` (`99f288c6…`) with `list-arrived.txt` | `listed_rule_a/`, `listed_b_reference/` |
| 5. R2: the six partial routes read as having an estimate | `r2/compare_test2_R2.py` (`905a6a77…`), the same change as `docs/research/extrapolation-data-3/measured/compare/R2_patch.diff` | `r2/` |

The inputs that were read: `../measured-240000/measure/attempts.jsonl`; the 120 records under `../measured-240000/variance_3/a240k3/db1/`; the committed `list-arrived.txt` and `cold-fail-marked.tsv`; the two prediction files. The first two repeats were not read. All SHA-256 (inputs, scripts, outputs) are in `sha256.txt`; the times of the steps are in `run_times.txt`. The scripts and the prediction files were not changed.

"(a)" is the count with the cold-fail rewrite of D256; "(b)" is the count of the records as they were. They give the same verdict and the same numbers.

The copies of the records that steps 2 and 3 made (`records-a`, `records-timeout`, the rewritten `attempts.jsonl` and `attempt.jsonl` files) are not kept: they are rebuilt from the records by steps 1 and 2.

## What the outputs say (numbers from the files here)

- **57 routes, rule B, R1: pass.** Rule A (shown only): pass. Denominator 56 (57 routes with a complete estimate, 1 not measured). Lowest standard: queries 54 of 56 (80% needs 45), `rows_examined` 52 of 56 (needs 45). Goal: queries 54, `rows_examined` 51. The four numbers of condition 1a are 21 of 22, 22 of 22, 54 of 56 and 52 of 56; the shortfall of each is 0. The verdict is not undecidable: the denominators are not under 5 and 1 of 57 (1.75%) could not be measured, below the 20% line. The one route that could not be measured is `GET /admin/customers/view/{id}` (the runner failed in the every-route measurement; left out of the repeat).
- **19 routes (D250, D252): pass.** Denominator 18; queries 16 of 18 and `rows_examined` 15 of 18 (needs 15, exactly on the line); goal for the unmarked routes 5 of 6 (queries, needs 5, also exactly on the line) and 6 of 6 (`rows_examined`). 1 of 19 (5.3%) could not be measured. Both counts have all four shortfalls at 0 and neither is undecidable, so condition 2 part A counts as met (D250 §4, D252); rule B passing on the 57 routes alone would not be enough.
- **Timeouts (D255):** 18 trials in 6 routes, all in the third repeat (lap 1, 2 and 3 of each): `DELETE /api/customer/wishlist/{id}`, `GET /admin/catalog/products/edit/{id}`, `GET /admin/marketing/promotions/cart-rules/edit/{id}`, `GET /api/customer/wishlist`, `GET /api/products/{id}/related`, `GET /api/products/{id}/up-sell`. All six are partial routes (estimate not complete) and are outside the denominator of R1. In the every-route measurement no per-trial `stderr` exists, so a timeout there cannot be observed; the records' `detail` fields and the run's logs hold no timeout text. `GET /api/products` took 799 to 845 s in the three laps but answered 200 in both requests of each trial; it is not a timeout.
- **Cold-fail rewrite (D256):** 7 routes: the six timeout routes and `GET /admin/customers/view/{id}`; 18 repeat records rewritten. The verdict and every count are the same with and without it, because the six routes are partial.
- **R2 (shown only):** with the six partial routes read as having an estimate, all six count as not measurable: 63 routes with an estimate, 56 in the denominator, 7 not measurable (11.1%, under 20%). Rule B and rule A pass. The route `GET /admin/marketing/promotions/cart-rules/edit/{id}` returned 500 at 30,000 rows in the capture, 500 in the every-route measurement and timed out in all three laps.
- **Offset marks (D253):** the prediction gives the `offset_chunk_fit` mark to 0 routes; 6 routes have an offset statement. Hits 0 and false alarms 0, so this test could not check whether the mark works. Misses: 6 (primary count), the routes outside the goal without an offset mark; of the 6 routes with an offset statement, 2 are outside the goal and so are misses: `GET /api/categories` (`rows_examined` +19.98%, marked in the plan) and `GET /api/categories/attributes/{attribute_id}/options` (queries +34.64%, not marked).
- **Condition 3 (routes outside the goal in R1):** 6 (5 marked, 1 not marked: `GET /api/categories/attributes/{attribute_id}/options`, queries +34.64%). The five marked ones: `GET /api/categories` (`rows_examined` +19.98%), `GET /api/categories/tree` (queries +12776.22%, `rows_examined` +3183.37%), `GET /customer/account/rma/get-order-items/{orderId}` (+311.11%), `POST /admin/sales/invoices/create/{order_id}` (+50.54%), `POST /customer/account/orders/cancel/{id}` (+113.72%). The causes are not investigated here.
- **Routes not counted (120 in all):** 57 complete estimates, 6 partial, 49 routes measured without an estimate that all failed when measured, and 8 routes with no measurement record (unfilled path parameters).

## Files

| Path | What it is |
| --- | --- |
| `ts/timeout-trials.tsv`, `ts/timeout-scan-summary.txt` | the timeout scan: the trials and the counts |
| `cf/cold-fail-marked.tsv`, `cf/cold-fail-marked-summary.txt` | the cold-fail rewrite list (the committed list is byte-identical) |
| `judge_a_rule_a/`, `judge_b_reference/` | `a-verdict.json` / `.md` (rule B decides, rule A shown, the four numbers of condition 1a, the routes outside the goal) and `R1/compare.json` / `.md` (the comparison tool's output) |
| `listed_rule_a/`, `listed_b_reference/` | the 19-route count: `count-listed.json` / `.md` / `.sha256`, `filtered/` (the two prediction files cut to the 19 routes), `judge/` (the verdict script on them) |
| `r2/` | R2: `compare_test2_R2.py`, `R2_patch.diff`, `R2_rule_a/`, `R2_b_reference/` |
| `sha256.txt`, `run_times.txt` | SHA-256 of inputs, scripts and outputs; the times of the steps |

No file here holds a home directory, a user name or a host name.
