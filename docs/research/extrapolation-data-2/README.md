# Data of the second extrapolation test (200,000 rows)

The estimates of the second extrapolation test (`docs/simulator-spec.md` 7.1, "200,000 件の測り方"), committed **before** any 200,000-row measurement. Per app: `fits.json` (route-level formulas of queries, rows examined and peak memory, from `bin/fit`), `predictions.json` (`bin/predict --at=200000`), `estimate.json` (`bin/estimate --candidate=H1_RANGE --at=200000 --predictions=predictions.json`) and `marked-routes.json` (`bin/marked-routes`, the marked routes).

What the files give per route (see `estimate.json`):

- `queries`: the sum over statement types of the fitted count at 200,000 (`queries_route_fit` is the route-level fit of `bin/predict`; it is `not_predicted` for a route whose static lower bound contradicts the fit)
- `rows_examined`: the H1 point (per statement type, fitted rows per run at the capture's sizes, times the count; the EXPLAIN is not used for the point)
- `rows_examined_upper`: only for a marked route, the upper side (the value of H2 for the marked statement types); `null` for the other routes
- `peak_memory`: the route-level fit of `bin/predict`
- `marked`: the route has at least one statement marked `plan_changes_at_scale` or `plan_unstable` in the EXPLAIN at 200,000 rows

The marked routes are made by that rule alone from the EXPLAIN (no measured value goes in; nothing is added or dropped by hand). Counts: verification app 0 of 24 routes in the EXPLAIN; BookStack 20 of 34 routes in the EXPLAIN (35 routes have an estimate; `GET /oidc/callback` has no entry in the EXPLAIN and so is not marked).

## The 12 routes of D105 (BookStack)

The 12 routes of D124 are fitted from the sizes 10, 100, 1,000 and 10,000 (the empty system has no point for them). Their capture, their EXPLAIN and their fits come from a separate run limited to those 12 routes; `merge_inputs.py` takes each of the 12 whole from that run and leaves every other route as it is. A route of the 12 missing from a file stops the merge. `POST /books/{bookSlug}/copy` is not among the 12 (D124): it has no estimate and stays outside the denominator.

## How the files were made

Inputs (the large ones are not in the repository; their SHA-256 are below):

- capture of the other routes (sizes 100, 1,000, 10,000) and of the 12 routes (sizes 10, 100, 1,000, 10,000): `bin/capture`
- EXPLAIN at 200,000 rows and 10,000 rows, three statistics states: `bin/explain-at-scale` (one run per app, one for the 12 routes)
- fits of the other routes: `bin/fit` on the record of the first extrapolation test (sizes 0, 100, 1,000, 10,000); the output for the verification app is byte-identical to the committed `docs/research/extrapolation-data/fixtures/fits.json`; for BookStack only the routes of the 12 (and two routes outside them) change their reason text, not their status

```
bin/fit --from=<record of the first test> > fits.json                                  # per app (BookStack: then merge_inputs.py)
python3 -I merge_inputs.py --out <dir> --capture ... --capture12 ... --explain ... --explain12 ... --fits ... --fits12 ...   # BookStack only
bin/predict --fits=fits.json --at=200000 > predictions.json
bin/estimate --capture=capture.json --explain=explain.json --candidate=H1_RANGE --at=200000 --predictions=predictions.json > estimate.json
bin/marked-routes --explain=explain.json > marked-routes.json
```

The same inputs give the same output (checked by running `bin/estimate` twice and comparing the bytes).

SHA-256 of the inputs the three tools read (after the merge for BookStack):

| Input | Verification app | BookStack |
| --- | --- | --- |
| capture.json | `1ed59ba7a0c94a388f11797915fd3d77b62d6e19844a537bb772b4a218f142a5` | `a2e1d267f6604f80d53ba340749c72e3f74a78f7f321b7bcf4452f7801b0bf21` |
| explain.json | `1823b1b3f0b518a37b430966de26e944223267d01c82b3609e6b1285fea22f9f` | `c037f7c660bf73b5a806f02cc2271858b7ac155ff48539ab60e3176f2a54aec5` |
| fits.json | `216f2b46435d85b9d6a45a2ac329debf4993cbfcdfa7d1f262a31ab717815c27` | `5f409c300b490463b9a198e677f301e09125d288a0d06d89fbce19c01a3a518f` |

## The routes whose peak memory held (`memory-accuracy.json`)

`memory_accuracy.py` reads the records of the two extrapolation tests (the list of routes within +-30% in `docs/research/extrapolation-test.md`, `extrapolation-data/<app>/fits.json`, and `compare.json` here) and writes `<app>/memory-accuracy.json`: the routes whose peak-memory formula was within +-30% in every test that measured them (`within`), and those measured outside it in any test (`outside`). The simulator shows the count N at which a route reaches the PHP memory line only for a `within` route (`docs/simulator-spec.md` 6.2). It reads only; the same inputs give the same files.

```
python3 -I memory_accuracy.py --out .
```

## The comparison (after the measurement)

`compare_test2.py` compares the committed estimates with the cold measurements at 200,000 rows and writes `compare.json` and `compare.md` (rule B decides, rule A is shown beside it; shares for all, unmarked and marked routes; the point and upper side of each marked route against its cold values; every try of the marked routes, with failed tries counted by class). `input_sha256.txt` lists the SHA-256 of every measurement record file it read; the records themselves are kept outside the repository. The record of the test is `docs/research/extrapolation-test-2.md`.

```
python3 -I compare_test2.py --out <dir> \
  --app fixtures  fixtures/estimate.json  fixtures/marked-routes.json  <measurement dir> <empty dir> \
  --app bookstack bookstack/estimate.json bookstack/marked-routes.json <measurement dir> <step 6 dir 1>,<step 6 dir 2>
```

It reads only; the same inputs give the same output (rebuilt and compared byte for byte). When a route appears in more than one directory of a list, the tries of the later directory replace the earlier ones for that route; the replaced tries are listed and not used.
