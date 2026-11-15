# Data of the third extrapolation test (300,000 rows)

The estimates of the third extrapolation test (`docs/simulator-spec.md` 7.1), made before any 300,000-row measurement of the test. The two estimate
files and the two lists of marked routes that the prediction gate reads are in `poc/lab/predictions/`
(`extrapolation-3-{fixtures,bookstack}-{estimate,marked}.json`); this directory holds where they came from. Per app: `fits.json` (route-level formulas,
the same file as in `../extrapolation-data-2/<app>/fits.json`, not refitted) and `predictions.json` (`bin/predict --at=300000`).

Nothing was measured at 300,000 rows to make these. The tools started were `bin/capture`, `bin/explain-at-scale`, `bin/predict`, `bin/estimate` and
`bin/marked-routes` only; `bin/measure` and `bin/variance-run` were not started (the start and end times are in `order_record.txt`, which has no line for
them). The large inputs (captures and EXPLAIN outputs) are not in the repository; their SHA-256 are in `sha256.txt` and in the per-app README.

Both estimates assume databases whose statistics were made up to date with `ANALYZE TABLE` (the captures were taken with `--analyze-after-build=on`).
The route `POST /books/{bookSlug}/copy` has an estimate but stays outside the comparison (D124, D205).

The marked routes here are those the EXPLAIN marks (`plan_changes_at_scale` or `plan_unstable`), the rule of 7.1 for test A. They are not the routes
marked by the memory side of the app limit (D196), which belongs to the lab backtest. Verification app: 0 of 24 routes. BookStack: 22 of 35 routes in the
EXPLAIN (36 routes have an estimate; `GET /oidc/callback` has no entry in the EXPLAIN).

Files: `sha256.txt` (all inputs and outputs of the run), `order_record.txt` (the tools started, with times), `fixtures/`, `bookstack/`.
How it was made is in D202.
