# Estimate of the lab backtest at 10,000 rows (BookStack, 8 routes)

The EXPLAIN at 10,000 rows, the size of the first rung of the lab backtest (`N_0 = 10,000`, the prediction scenario that does not grow the data),
read into an estimate the same way as the 30,000 and 100,000 rows ones (`docs/research/lab-data/estimate-30k/`, `docs/research/lab-data/estimate/`).
It is the marks of the EXPLAIN at the size of the scenario that the safe side of that scenario needs; the point is the same formula as in
`estimate/`. It is committed **before** any prediction scenario is measured in the lab. Nothing in `estimate/` or `estimate-30k/` is replaced or edited.

The EXPLAIN itself is the one made for `estimate/` (the 10,000-row run of the same family, kept outside the repository; its SHA-256 is already
recorded in `estimate/README.md`). No database was built and no statement was run for this directory: it is `bin/estimate` and `bin/marked-routes`
on that existing file.

No prediction is made here. Files:

- `estimate.json`: `bin/estimate --candidate=H1_RANGE --with-formulas --axes-only` at 10,000 rows, 8 routes, all complete (same inputs and flags as the 100,000-row one, only `--at` and the EXPLAIN differ).
- `marked-routes.json`: `bin/marked-routes` on the EXPLAIN at 10,000 rows compared with 1,000 rows (one tenth of the size, the way `estimate/` was made): 3 of the 7 routes that have an EXPLAIN entry.

## What is marked at 10,000 rows

- Marked routes: `GET /books/{bookSlug}/export/zip`, `GET /books/{bookSlug}/page/{pageSlug}/references`, `GET /books/{slug}` (3 routes; at 100,000 rows: `export/zip`, `/books/{slug}`, `/shelves/{slug}`; at 30,000 rows against 3,000: the union of the two, 4 routes).
- `plan_unstable` is absent at 10,000 rows; `plan_changes_at_scale` marks 7 statement types of the 76.
- The point formulas (count and rows per run, with the three two-scale lines) are the same in all three estimates: the statements and the capture are the same. What differs is the upper side, the statistics states and the marks, which come from the EXPLAIN at each size.

## How the files were made

```
bin/predict --fits=docs/research/extrapolation-data-2/bookstack/fits.json --at=10000 > predictions.json   # read for route, axis and table only
bin/estimate --capture=<recapture>/capture.json --explain=<reexplain>/bookstack-n10k/explain.json \
  --candidate=H1_RANGE --at=10000 --predictions=predictions.json --axes-only --with-formulas > estimate.json
bin/marked-routes --explain=<reexplain>/bookstack-n10k/explain.json > marked-routes.json
```

The same inputs give the same output (`bin/estimate` run twice, bytes compared). As a check of the chain, the same commands at 100,000 rows
(`--at=100000`, the 100,000-row EXPLAIN) reproduce the committed `estimate/estimate.json` byte for byte (SHA-256 `8068e8d3...`).

| Input | SHA-256 |
| --- | --- |
| `capture.json` (the re-capture, same as `estimate/`) | `8bf76bbed94393410ec1d695d97478f7cf85011718ae7c0c9b90fed1420e9ce5` |
| `explain.json`, 10,000 rows vs 1,000 (used) | `1de82e71834365197d5420f28402be0958f7cd1db2f1e21c4b16cad6872de0e9` |
| `fits.json` (older; only the axes pass through `predict`) | `5f409c300b490463b9a198e677f301e09125d288a0d06d89fbce19c01a3a518f` |
| `predictions.json` at 10,000 (not committed) | `60a003a49fe40de4298dcac63aef50e5afc0fd8e652f96d51941aef3c1d5c0b5` |

SHA-256 of the committed outputs:

| Output | SHA-256 |
| --- | --- |
| `estimate.json` | `f6e31fe09fc9c3dcae353d40e9e397f3c3a8ea751a3b6192ed2bb7892aa68e89` |
| `marked-routes.json` | `cc2a500b6b5549f77b9eba25e7eedf3dd20d523655ccfaf6ff6ba56d75d8805e` |
