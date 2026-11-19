# Fifth extrapolation round, test A at 240,000 rows: the verification app (estimate and marked routes)

The estimate of the verification app for test A of the fifth round (`docs/simulator-spec.md` 7.1, 7.4.4), made before any 240,000-row measurement. The estimate and the list of
marked routes that the prediction gate reads are in `poc/lab/predictions/extrapolation-4-fixtures-{estimate,marked}.json`; this directory holds where they came from.
`fits.json` is the same file as `../../extrapolation-data-3/fixtures/fits.json` (not refitted). `predictions.json` is `bin/predict --at=240000`.

Nothing was measured at 240,000 rows to make these. The only requests that reached a 240,000-row database were the EXPLAIN statements of `bin/explain-at-scale` (its gate lets
`EXPLAIN FORMAT=JSON <data statement>` through and refuses anything else), plus the tool's own build, row counts and `ANALYZE TABLE`. No HTTP request and no measurement was
made against it. The database was a throw-away one in a stack of its own (the lab's databases were not touched) and was dropped by the tool; the stack was taken down without `-v`.

Tool: `git archive` of `c72478d483f6f647d568cfb36e5d065e5762eb3c` (`poc/runtime`, `poc/lab/bin`, `merge_inputs.py`).

The capture below was taken in the third round with the tool at `8f4cd65` (recorded in `../../extrapolation-data-3/order_record.txt`). Against that tool, nothing the databases are
built from changed: the generator (`poc/runtime/src/Data`), the verification app (`fixtures/laravel-app`) and `poc/runtime/src/Measure/DatabaseBuild.php` have 0 changed files, and
`poc/lab/bin/build-db` differs by 4 comment lines only. The measuring and prediction scripts did change since `8f4cd65` (route order, the rebuild step added and then removed,
the allocation flag), none of which this test's EXPLAIN step reads. Against `6ae5195` (the tool of the rehearsal) the build and generator paths (`poc/runtime/src/Measure`,
`poc/runtime/bin`, `poc/runtime/profiles`, `poc/runtime/src/Data`, `poc/lab/bin/build-db`) have 0 changed files; the only files that changed anywhere in `poc/runtime` and
`poc/lab/bin` are `poc/lab/bin/measure-ladder` and `poc/lab/bin/measure-ladder-chain`, which the lab backtest uses and this test does not.

```
bin/explain-at-scale --capture=capture.json --tasks=tasks.json --app=fixtures/laravel-app --scale=240000 --label=a-fixtures-n240   # EXPLAIN only; small side = the default 10,000
bin/predict --fits=fits.json --at=240000 > predictions.json
bin/estimate --capture=capture.json --explain=explain.json --candidate=H1_RANGE --at=240000 --predictions=predictions.json > estimate.json    # no --with-formulas; run twice
bin/marked-routes --explain=explain.json > marked-routes.json                                                                               # run twice
```

- Capture: the verification app's capture of the third round, 24 routes, row counts 100 / 1,000 / 10,000 / 30,000. It was not retaken: the app has no route that changes a table
  other routes read, so the order of its routes could not have changed what was captured. Its SHA-256 is in `sha256.txt`.
- The small side of the EXPLAIN comparison is the tool's default, 10,000 rows, the same as the A rounds at 200,000 and 300,000. (An EXPLAIN taken first with a small side of 24,000 was
  set aside and not used; see `order_record.txt`. It marked 0 routes too and gave the same estimate byte for byte.)
- Both runs of the estimate are identical byte for byte, and so are both runs of the marked-routes list.
- `estimate.json` has 24 routes; its `small_scale` is 30,000 (the tool takes it from the largest captured size, as in the third round).
- The EXPLAIN has 43 statement types: 0 `plan_unstable`, 0 `plan_changes_at_scale`, 0 `explain_unavailable`. Marked routes: 0 of 24.
- The note in the estimate assumes the capture and the EXPLAIN databases are in the same maintenance state: here neither had a rebuild of a table run on it (the app has none), and `ANALYZE TABLE` was run on both.

Files: `sha256.txt` (inputs and outputs), `order_record.txt` (start and end of each tool, with times; the first line names the tool commit).
