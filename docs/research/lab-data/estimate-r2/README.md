# Estimate with the tangent rule (R2) at 10,000, 30,000 and 100,000 rows

The estimate of `estimate/`, `estimate-30k/` and `estimate-10k/` made again with one rule of `bin/estimate` changed (below), from the same inputs, at the same three row counts. Nothing is measured here and no prediction is made. The older directories are kept as they were; they were made by the code before the rule (the parent of commit `f029e9b`, which added it) and can be reproduced with that code. The records of the backtest and of `diagnosis/` are unchanged.

## What the rule is

The estimate of a route is the sum over its statement types of `rows per run x runs`. The rows per run of a type is a fit `a + b * N^k` of what the capture read at the sizes 100, 1,000 and 10,000, put at the target size. For a type whose reading jumps between two of those sizes (the plan the database picks changes there), the fit reads the jump as growth: `k` comes out above 1 and the fit keeps accelerating ten times past the largest size.

In the capture, `GET /books/{slug}` and the ZIP export share one statement (`s7ff3e12408`, `select * from entities left join entity_container_data ... where book_id = ? ...`). Its rows per run are 61, 601 and 25,999 at 100, 1,000 and 10,000 rows: a `ref` on `entities_type_index` at the first two sizes and an `index_merge` intersect at 10,000. The fit has `k` = 1.67, and the estimate for it at 100,000 rows is 1,213,881. `GET /books/{slug}` has a second statement of the same shape (`s24834a57a6`, `k` = 1.64).

**The rule.** Above the largest size of the capture, a fit whose `k` is above 1 goes on along its tangent at that size: the fit's value there and its slope there, `k x (value - a) / size`, instead of its own curve. The curve is kept as the upper side of the type, and so a route with such a type has an upper side (`rows_examined_upper`), as a marked route has. At or below the largest size, and for a fit whose `k` is not above 1, nothing changes. The formulas the estimator writes (`--with-formulas`) carry `linear_beyond` (`scale`, `value`, `slope`) for such a type, and the simulator's `EstimateFormulas::evaluate` applies it at the size of each scenario.

The rule has no constant to set. It was chosen after the records at 30,000 and 100,000 rows had been read: a first form (the reading at the largest size, grown in proportion to the size) was tried on them too and left under the measured by 36% to 38%, the tangent by 15% to 22%. So these three sizes do not test the rule; the check that does is a size not looked at (70,000 rows).

## Rows read and statements: before and after

Measured values are the cold in-process request of `diagnosis/per-request/` (median of the three runs). "Statements" is the estimate over the measured. "Old" is `estimate/`, `estimate-30k/` and `estimate-10k/`; "new" is the files in this directory.

| rows | route | statements est / measured | rows measured | rows, old (old / measured) | rows, new (new / measured) | upper side, new (upper / measured) |
| --- | --- | --- | --- | --- | --- | --- |
| 10,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | 8.0 / 8 | 222 | 224 (1.01) | 224 (1.01) | none |
| 10,000 | `/books/{bookSlug}/export/zip` | 12003.9 / 12004 | 53,698 | 53,733 (1.00) | 53,733 (1.00) | 173,251 (3.23) |
| 10,000 | `/books/{bookSlug}/page/{pageSlug}/references` | 8.0 / 8 | 225 | 225 (1.00) | 225 (1.00) | 386 (1.71) |
| 10,000 | `/books/{slug}` | 46.5 / 45 | 78,460 | 78,468 (1.00) | 78,468 (1.00) | 561,747 (7.16) |
| 10,000 | `/books/{slug}/references` | 7.0 / 7 | 217 | 218 (1.01) | 218 (1.01) | none |
| 10,000 | `/images/edit/{id}` | 6.0 / 6 | 199 | 199 (1.00) | 199 (1.00) | none |
| 10,000 | `/oidc/callback` | 0.0 / 0 | 0 | 0 (n/a) | 0 (n/a) | none |
| 10,000 | `/shelves/{slug}` | 18.0 / 18 | 237 | 10,236 (43.19) | 10,236 (43.19) | none |
| 30,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | 8.0 / 8 | 222 | 224 (1.01) | 224 (1.01) | none |
| 30,000 | `/books/{bookSlug}/export/zip` | 36001.7 / 36004 | 250,696 | 245,392 (0.98) | 195,484 (0.78) | 262,252 (1.05) |
| 30,000 | `/books/{bookSlug}/page/{pageSlug}/references` | 8.0 / 8 | 229 | 225 (0.98) | 225 (0.98) | 389 (1.70) |
| 30,000 | `/books/{slug}` | 47.9 / 45 | 378,454 | 392,267 (1.04) | 299,722 (0.79) | 672,301 (1.78) |
| 30,000 | `/books/{slug}/references` | 7.0 / 7 | 217 | 218 (1.01) | 218 (1.01) | none |
| 30,000 | `/images/edit/{id}` | 6.0 / 6 | 199 | 199 (1.00) | 199 (1.00) | none |
| 30,000 | `/oidc/callback` | 0.0 / 0 | 0 | 0 (n/a) | 0 (n/a) | none |
| 30,000 | `/shelves/{slug}` | 18.0 / 18 | 236 | 30,236 (128.12) | 30,236 (128.12) | 30,236 (128.12) |
| 100,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | 8.0 / 8 | 222 | 224 (1.01) | 224 (1.01) | none |
| 100,000 | `/books/{bookSlug}/export/zip` | 119994.0 / 120004 | 835,196 | 1,489,446 (1.78) | 691,612 (0.83) | 1,491,966 (1.79) |
| 100,000 | `/books/{bookSlug}/page/{pageSlug}/references` | 8.0 / 8 | 229 | 225 (0.98) | 225 (0.98) | none |
| 100,000 | `/books/{slug}` | 49.5 / 45 | 1,260,454 | 2,539,961 (2.02) | 1,074,076 (0.85) | 2,539,961 (2.02) |
| 100,000 | `/books/{slug}/references` | 7.0 / 7 | 217 | 218 (1.01) | 218 (1.01) | none |
| 100,000 | `/images/edit/{id}` | 6.0 / 6 | 199 | 199 (1.00) | 199 (1.00) | none |
| 100,000 | `/oidc/callback` | 0.0 / 0 | 0 | 0 (n/a) | 0 (n/a) | none |
| 100,000 | `/shelves/{slug}` | 18.0 / 18 | 236 | 100,236 (424.73) | 100,236 (424.73) | 100,236 (424.73) |

## What it shows

- The two routes that carried the miss at 100,000 rows are the only two whose figures change, and only at 30,000 and 100,000 rows: `GET /books/{slug}` 2.02 to 0.85, the ZIP export 1.78 to 0.83. At 10,000 rows (the largest size of the capture) no figure changes. The other six routes are the same in every column at all three sizes.
- At 30,000 rows the two routes were close before (1.04 and 0.98) and are now 0.79 and 0.78. The estimate is lower than the measured at both sizes, by a similar amount (0.78 to 0.85), where it was close at 30,000 and twice the measured at 100,000. The measured rows of the shared statement per row of the table are not constant over the sizes: from the totals of the records and the statements that grow exactly linearly, about 0.6, 0.6, 2.6, 5.6 and 5.6 rows per row of the table at 100, 1,000, 10,000, 30,000 and 100,000 rows. The capture reaches 10,000 rows, in the middle of the change, so what it sees of the rate is not what it settles at; the tangent follows part of the way, and the upper side (the old curve) is above the measured at 30,000 and 100,000 rows.
- The number of statements is not changed by the rule (1.03 to 1.10 for `GET /books/{slug}`, 1.00 for the ZIP export).
- `GET /shelves/{slug}` is not touched by this rule: its fit is a straight line (`k` = 1), and its miss has another cause (D175).

## How the files were made

Inputs are kept outside the repository (the same as `estimate/`, `estimate-30k/` and `estimate-10k/`):

```
bin/predict --fits=docs/research/extrapolation-data-2/bookstack/fits.json --at=<N> > predictions.json   # read for route, axis and table only
bin/estimate --capture=<recapture>/capture.json --explain=<explain.json for N> \
  --candidate=H1_RANGE --at=<N> --predictions=predictions.json --axes-only --with-formulas > estimate.json
```

`<explain.json for N>` is the one used for the older directory of the same size (100,000 rows against 10,000; 30,000 against 3,000; 10,000 against 1,000). The same inputs give the same output (each command run twice, bytes compared). Run with the code before the rule, the same commands give the older files byte for byte (SHA-256 below), so only the rule differs.

| Input | SHA-256 |
| --- | --- |
| `capture.json` | `8bf76bbed94393410ec1d695d97478f7cf85011718ae7c0c9b90fed1420e9ce5` |
| `explain.json`, 100,000 rows | `9f95a7a52b430a1bf2d550ad235d13006388110c31d80155fd1a9fc463a133da` |
| `explain.json`, 30,000 rows (against 3,000) | `dc865842ff72f79c970901ce4a428e91931f2b1506b744f255b627f70c57a050` |
| `explain.json`, 10,000 rows | `1de82e71834365197d5420f28402be0958f7cd1db2f1e21c4b16cad6872de0e9` |
| `fits.json` (older; only the axes pass through `predict`) | `5f409c300b490463b9a198e677f301e09125d288a0d06d89fbce19c01a3a518f` |

| Output | SHA-256 |
| --- | --- |
| `100k/estimate.json` | `d405b4ae5ba71731ca578b9cb11b56a70461a6b8d1a43c8d0ee03603eb845bea` |
| `30k/estimate.json` | `71e4bc58f5b356f5958026e00d6089e3b6d61cf9d8d660e56273b09f4b47a321` |
| `10k/estimate.json` | `83c5832d3c9168d2e682430c058da64c6008016a7fbbe4eb6c7f0fba0cdbc7a2` |
