# Estimate of the lab backtest at 30,000 rows (BookStack, 8 routes)

The EXPLAIN at 30,000 rows, the size of the first prediction scenario (3 x N_0 with N_0 = 10,000), made the same way as the
10,000 and 100,000 rows ones in `docs/research/lab-data/estimate/` (D150): the tables the 8 routes scale (`entities`, `images`,
`references`) grow together in one database, EXPLAIN only, no statement is run and no load is applied. It is committed **before** any
prediction scenario is measured in the lab (D150, condition 3). Nothing in `estimate/` is replaced or edited.

No prediction is made here. Files:

- `estimate.json`: `bin/estimate --candidate=H1_RANGE --with-formulas --axes-only` at 30,000 rows, 8 routes, all complete (same inputs and flags as the 100,000-row one, only `--at` and the EXPLAIN differ).
- `marked-routes.json`: `bin/marked-routes` on the EXPLAIN at 30,000 rows compared with 3,000 rows (one tenth of the target, the way `estimate/` was made): 4 of the 7 routes that have an EXPLAIN entry.
- `marked-routes-small-10k.json`: the same on the EXPLAIN at 30,000 rows compared with 10,000 rows (the small size the 100,000-row EXPLAIN was compared with): 3 of the 7 routes.

The two EXPLAIN runs differ only in the small size the plans are compared with, so they share the 30,000-row plans themselves
(76 statement types, 3 `plan_unstable`, 0 unavailable). `plan_changes_at_scale` is 9 against 3,000 rows and 3 against 10,000 rows.

## Marked statements, compared with the 10,000 and 100,000-row EXPLAIN

`changes` = `plan_changes_at_scale`, `unstable` = `plan_unstable`, `-` = not marked. The 10,000 and 100,000-row columns are the committed
EXPLAIN of `estimate/` (10,000 compared with 1,000; 100,000 compared with 10,000).

| Route | Statement | 10,000 (vs 1,000) | 30,000 (vs 3,000) | 30,000 (vs 10,000) | 100,000 (vs 10,000) |
| --- | --- | --- | --- | --- | --- |
| `GET /books/{bookSlug}/export/zip` | `s0fc8c91c7d` | changes | changes | changes | changes |
| `GET /books/{bookSlug}/export/zip` | `s7ff3e12408` | changes | changes | - | - |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | `s2abffb28d9` | changes | changes | - | - |
| `GET /books/{slug}` | `s24834a57a6` | changes | changes | - | - |
| `GET /books/{slug}` | `s400220f42f` | changes | changes | - | - |
| `GET /books/{slug}` | `s58efd897dc` | changes | changes | - | - |
| `GET /books/{slug}` | `s7ff3e12408` | changes | changes | - | - |
| `GET /books/{slug}` | `se916fbea31` | - | unstable | unstable | unstable |
| `GET /books/{slug}` | `sf034e6d815` | - | changes, unstable | changes, unstable | unstable |
| `GET /shelves/{slug}` | `sf034e6d815` | - | changes, unstable | changes, unstable | unstable |

What changed:

- Marked routes: 10,000 rows: 3 routes (`export/zip`, `.../references`, `/books/{slug}`); 100,000 rows: 3 routes (`export/zip`, `/books/{slug}`, `/shelves/{slug}`). At 30,000 rows against 3,000 rows: 4 routes, the union of the two. Against 10,000 rows: the same 3 routes as at 100,000 rows.
- `plan_unstable` is absent at 10,000 rows and present at 30,000 rows, on the same statements as at 100,000 rows (`sf034e6d815` on `/books/{slug}` and `/shelves/{slug}`, `se916fbea31` on `/books/{slug}`). So the statistics-state instability starts between 10,000 and 30,000 rows.
- The route `GET /books/{bookSlug}/page/{pageSlug}/references` is marked only when the plan is compared with 3,000 rows (a plan that is not the same at 3,000 as at 10,000 and above). It is the only route whose mark depends on the small size chosen.

## How the files were made

The inputs and the code are those of `estimate/` (same capture, same task list, same code snapshot of `bin/explain-at-scale`); each EXPLAIN run built one database of 30,000 rows with the three tables grown together and one of the small size, in a throwaway stack of its own (not the lab's), and dropped them.

```
PEAK_COMPOSE_PROJECT=<project> bin/explain-at-scale --capture=<recapture>/capture.json --tasks=<recapture>/tasks.json --app=<bookstack> \
  --scale=30000 --small-scale=3000 --n-tables=entities,images,references --out=<out> --label=bookstack-n30k --php-image=peak-measure-php-bookstack:8.3
# the second run: --small-scale=10000 --label=bookstack-n30k-s10k
bin/predict --fits=docs/research/extrapolation-data-2/bookstack/fits.json --at=30000 > predictions.json   # read for route, axis and table only
bin/estimate --capture=<recapture>/capture.json --explain=<out>/bookstack-n30k/explain.json \
  --candidate=H1_RANGE --at=30000 --predictions=predictions.json --axes-only --with-formulas > estimate.json
bin/marked-routes --explain=<out>/bookstack-n30k/explain.json > marked-routes.json
bin/marked-routes --explain=<out>/bookstack-n30k-s10k/explain.json > marked-routes-small-10k.json
```

The same inputs give the same output (`bin/estimate` and `bin/marked-routes` run twice, bytes compared). As a check of the chain, the
same commands at 100,000 rows reproduce the committed `estimate/estimate.json` byte for byte (SHA-256 below).

| Input | SHA-256 |
| --- | --- |
| `capture.json` (the re-capture, same as `estimate/`) | `8bf76bbed94393410ec1d695d97478f7cf85011718ae7c0c9b90fed1420e9ce5` |
| `tasks.json` (same as `estimate/`) | `2dd8145996fc89e35a2af7d938f5b57597683fbbc9c542f68feaed4cdbd7f815` |
| `explain.json`, 30,000 rows vs 3,000 (used) | `dc865842ff72f79c970901ce4a428e91931f2b1506b744f255b627f70c57a050` |
| `explain.json`, 30,000 rows vs 10,000 | `3bc86356b6a981a6c54dfe7c7f73c7a3978aa28d046e30db1851c7aabd5ab1ba` |
| `fits.json` (older; only the axes pass through `predict`) | `5f409c300b490463b9a198e677f301e09125d288a0d06d89fbce19c01a3a518f` |
| `predictions.json` at 30,000 (not committed) | `d67e79d23007d2e656b1d6eb70c45c38d0d5c1fc256b5a49d497938e9cd24691` |
| `estimate.json` at 100,000 rows made again (equal to `estimate/estimate.json`) | `8068e8d3445c49f2a8a9e8a2b40d767ce2ee26cb28dc577bccebd5bb09426aa4` |

SHA-256 of the committed outputs:

| Output | SHA-256 |
| --- | --- |
| `estimate.json` | `bbb06f840f0f45ddb5ccc199e351fef78ce82256e0953927a15725da84de9123` |
| `marked-routes.json` | `628a754d76ddc3df19c715f6d3240d521d75d7ca9958be6343b737918219c2c7` |
| `marked-routes-small-10k.json` | `0dbf0bd6812080fa52ec718512eb310bf50dcb9a752194acd98063957c90b4d7` |
