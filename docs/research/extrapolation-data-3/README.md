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

## Changes to the copies

- After the first commit the copies in this directory were changed again, to take the names of the working environment out. The sha256 given here is of each file as it was committed before this change, so these files no longer have the bytes the tools wrote. The home directory of the working machine is written `<home>`; in directory, volume and label names the short word that named the operator's task before a number is written `run-` (the number and the rest of the name are kept, so one place has one name in every file, for example `run-281t6b`, `run-277t6`, `run-277_recapture2`); these names differ from the paths the runs really used. Only the `*.wall.txt` files of `measured/` held such names (the directory of the tool copy under the trials' `_scratch/`). The same names are written the same way in `../extrapolation-data-4/measured-240000/`. sha256 before, and the number of lines changed: `measured/bookstack.wall.txt` 28878b14ccffad5ea9ecffbd6734eed94070bc428a70be26453186559bb5608b (1 line); `measured/fixtures.wall.txt` 9ac56222076ba3d1220b75e99cf8047c7c4593696a2ee8c859bef1fa319e5b08 (1 line); `measured/repeat.wall.txt` 8145209c7d1d11b75c32cb37712c6c1b6935cdb8cc8f1e3fb03e8f7b75b9bbb4 (1 line).
