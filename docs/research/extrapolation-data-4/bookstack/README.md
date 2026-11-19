# Fifth extrapolation round, test A at 240,000 rows: BookStack (estimate and marked routes)

The estimate of BookStack for test A of the fifth round (`docs/simulator-spec.md` 7.1, 7.4.4), made before any 240,000-row measurement. The estimate and the list of marked
routes that the prediction gate reads are in `poc/lab/predictions/extrapolation-4-bookstack-{estimate,marked}.json`; this directory holds where they came from.
`fits.json` is the same file as `../../extrapolation-data-3/bookstack/fits.json` (not refitted; same SHA-256). `predictions.json` is `bin/predict --at=240000`.

Nothing was measured at 240,000 rows to make these. The only requests that reached a 240,000-row database were the EXPLAIN statements of `bin/explain-at-scale` (its gate lets
`EXPLAIN FORMAT=JSON <data statement>` through and refuses anything else), plus the tool's own build, row counts and `ANALYZE TABLE`. No HTTP request and no measurement was
made against it. The databases were throw-away ones in two stacks of their own (the lab's databases were not touched) and were dropped by the tool. Both stacks were taken down
without `-v`; the anonymous MySQL volume of each stack stayed (two volumes, created 17:20, not removed here).

Tool: `git archive` of `c72478d483f6f647d568cfb36e5d065e5762eb3c` (`poc/runtime`, `poc/lab/bin`, `merge_inputs.py`).

Captures: the retaken BookStack captures of the fifth round (`cmd286_t2r`: 31 routes at 100 / 1,000 / 10,000 / 30,000 rows, and the 12 routes of D105 at 10 .. 30,000 rows),
taken without a rebuild of any table and with the routes that rewrite a table measured last in each database. The 12 routes are merged into the capture and the EXPLAIN of
the main run with `../../extrapolation-data-2/merge_inputs.py`, as in the third round (36 routes with an estimate; the EXPLAIN has 35, `GET /oidc/callback` has none).
The older third-round capture was not used. SHA-256 of every input and output: `sha256.txt`.

The capture tool was `6ae5195`. Against it, `poc/runtime` has 0 changed files (so the generator, the profiles and the database build have none), `fixtures/` has 0, and the
only files that changed in `poc/runtime` and `poc/lab/bin` together are `poc/lab/bin/measure-ladder` and `poc/lab/bin/measure-ladder-chain`, which this step does not read.

```
bin/explain-at-scale --capture=capture.json --tasks=tasks.json --app=<bookstack> --scale=240000 --label=a-bookstack-n240 --keep-stack      # EXPLAIN only; small side = the default 10,000
bin/explain-at-scale ... (the 12 routes' capture and tasks) --label=a-bookstack12-n240 --keep-stack
python3 merge_inputs.py ...                                                                                                                # the 12 routes merged in
bin/predict --fits=fits.json --at=240000 > predictions.json
bin/estimate --capture=capture.json --explain=explain.json --candidate=H1_RANGE --at=240000 --predictions=predictions.json > estimate.json  # no --with-formulas; run twice
bin/marked-routes --explain=explain.json > marked-routes.json                                                                              # run twice
```

- Why the small side is 10,000: it is the tool's default and the rule of the A rounds at 200,000 and 300,000 (D199 (9)); changing it would change how the A marks are made. It was
  decided before the EXPLAIN. The 3,000 of the 30,000-row rehearsal belongs to that rehearsal only.
- Both runs of the estimate are identical byte for byte, and so are both runs of the marked-routes list.
- `estimate.json` has 36 routes; its `small_scale` is 30,000 (the tool takes it from the largest captured size, as in the third round).
- The EXPLAIN has 1,282 statement types: 5 `plan_unstable`, 21 `plan_changes_at_scale`, 8 `explain_unavailable`. **Marked routes: 21 of 35** (22 of 35 at 300,000 rows in the third round).
- Build and EXPLAIN took: main run 17:20:22 to 17:38:15 (22 database builds, 905 s of building in all, longest 208 s); 12-route run 17:20:23 to 17:30:13 (8 builds, 531 s, longest 243 s).
  The two ran side by side. Whole step, from the start of the tools to the estimate files: 17:20:22 to 17:38:33 (the estimate tools took 4 s).
- No rebuild of a table was run on any of these databases (`regenerate-references` is not in the build); `ANALYZE TABLE` was run on all of them. The note in the estimate assumes the capture and
  the EXPLAIN databases are in the same maintenance state; they are, in that neither had a rebuild run on it.

Files: `sha256.txt` (inputs and outputs), `order_record.txt` (start and end of each tool, with times; the first line names the tool commit).
