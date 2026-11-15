# Test A at 300,000 rows: the verification app

Tool: `git archive` of `8f4cd65` (it carries the build limit of 7,200 s, D200). Estimate = `bin/estimate --candidate=H1_RANGE --at=300000 --predictions=predictions.json`
(without `--axes-only`), made twice and compared byte for byte. Fits are those of the second test, as they are.

```
bin/capture --data=seeder --scales=100,1000,10000,30000 --analyze-after-build=on        # 24 routes
bin/explain-at-scale --scale=300000                                                        # small side: the tool's default 10,000
bin/predict --fits=fits.json --at=300000 > predictions.json
bin/estimate --capture=capture.json --explain=explain.json --candidate=H1_RANGE --at=300000 --predictions=predictions.json > estimate.json
bin/marked-routes --explain=explain.json > marked-routes.json
```

`estimate.json` has 24 routes and its `small_scale` is 30000 (the tool takes it from the largest captured size; the EXPLAIN's small side is the separate 10,000).
Marked routes: 0 of 24.

| File | SHA-256 |
| --- | --- |
| `capture.json` (not in the repository) | `37a8dcd9383a157308d3e5651a6594cbe1d588f0321c9960be13b6d75af753b8` |
| `explain.json` (not in the repository) | `410625d7c23de13557586fb877839aef61860acc3bfbac092649d7a613ae9bb9` |
| `fits.json` | `216f2b46435d85b9d6a45a2ac329debf4993cbfcdfa7d1f262a31ab717815c27` |
| `predictions.json` | `2550bc464b30a99fba77e88cd890fc922542057790d77c4ec582feb54fe2047d` |
| `estimate.json` = `poc/lab/predictions/extrapolation-3-fixtures-estimate.json` | `5c341692b9a329d195a798293877ac9af76b1a2df8213e439a6db105d55c4185` |
| `marked-routes.json` = `poc/lab/predictions/extrapolation-3-fixtures-marked.json` | `6990173cb6e732a73c0257c30cc501d8a7671dcee6b836fc71ed06362ed1091e` |
