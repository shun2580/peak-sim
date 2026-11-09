# Estimate of the lab backtest (BookStack, 8 routes)

The estimate that the lab backtest (`docs/simulator-spec.md` 7.2) predicts from, made again the way the lab builds its data (D149): the tables the 8 routes scale (`entities`, `images`, `references`) grow together, in one shared database. It is committed **before** any prediction scenario is measured in the lab (D150, condition 3). The older estimate, in which each route's own table alone was grown (`docs/research/extrapolation-data-2/`), is left as it was; nothing here replaces or edits it.

No prediction is made here. Files:

- `estimate.json`: `bin/estimate --candidate=H1_RANGE --with-formulas --axes-only`, at 100,000 rows (the largest target size, 10 x N_0), 8 routes, all complete.
- `marked-routes.json`: `bin/marked-routes` on the EXPLAIN at 100,000 rows (3 of the 7 routes that have an EXPLAIN entry).

## What is in it, and what is not

- Count and rows per statement type come from the new capture (sizes 100, 1,000, 10,000, tables grown together) and the new EXPLAIN (10,000 and 100,000 rows, same way of growing, three statistics states).
- `--axes-only` (D151): the route, axis and table of each route come from the static analysis through `bin/predict`; none of its quantities are read. So the route-level peak memory and the route-level query fit are `not_given`, and `peak_memory_formula` is `null`. The sum of the per-type counts is the query count of a route. The backtest decides by utilisation only (spec 7.4 B), and no value of the older measurements is mixed in.
- `GET /oidc/callback` has no statement in the capture (0 queries, no EXPLAIN entry), so it costs 0 in the estimate and is not marked.
- A statement type whose plan differs between statistics states is marked `plan_unstable` and uses its heaviest state, by the rule of spec 4.3. Nothing was added or dropped by hand.

## How the files were made

Inputs are kept outside the repository; their SHA-256 are below. `<recapture>` is the output directory of the re-capture (`bin/capture` with `--n-tables`), `<reexplain>` that of `bin/explain-at-scale` with `--n-tables=entities,images,references` (one run per size, small size one tenth of the target).

```
bin/predict --fits=docs/research/extrapolation-data-2/bookstack/fits.json --at=100000 > predictions.json   # read for route, axis and table only
bin/estimate --capture=<recapture>/capture.json --explain=<reexplain>/bookstack-n100k/explain.json \
  --candidate=H1_RANGE --at=100000 --predictions=predictions.json --axes-only --with-formulas > estimate.json
bin/marked-routes --explain=<reexplain>/bookstack-n100k/explain.json > marked-routes.json
```

The same inputs give the same output (`bin/estimate` run twice, bytes compared).

| Input | SHA-256 |
| --- | --- |
| `capture.json` (re-capture, 8 routes) | `8bf76bbed94393410ec1d695d97478f7cf85011718ae7c0c9b90fed1420e9ce5` |
| `tasks.json` (the tasks the capture ran) | `2dd8145996fc89e35a2af7d938f5b57597683fbbc9c542f68feaed4cdbd7f815` |
| `explain.json` at 100,000 rows (used) | `9f95a7a52b430a1bf2d550ad235d13006388110c31d80155fd1a9fc463a133da` |
| `explain.json` at 10,000 rows (same run family; not read by the estimate) | `1de82e71834365197d5420f28402be0958f7cd1db2f1e21c4b16cad6872de0e9` |
| `fits.json` (older; only the axes pass through `predict`) | `5f409c300b490463b9a198e677f301e09125d288a0d06d89fbce19c01a3a518f` |
| `predictions.json` (made from the older `fits.json`; not committed) | `9c0ef762aff231787e2330dbacc72c8dfd9062822541d7bcbd258a57bf42a2ca` |

SHA-256 of the committed outputs:

| Output | SHA-256 |
| --- | --- |
| `estimate.json` | `8068e8d3445c49f2a8a9e8a2b40d767ce2ee26cb28dc577bccebd5bb09426aa4` |
| `marked-routes.json` | `33b13c343e3d670d32a8dc4dd34cbbb2f8234f97efbfdf31db664de15e85aa04` |
