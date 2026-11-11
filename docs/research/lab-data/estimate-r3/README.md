# Estimate from a capture made on analyzed databases, with and without the tangent rule

The row estimate of `estimate-r2/` made again from a new capture. Nothing is measured against the lab here and no prediction is made. The capture was repeated for two reasons that the records before it gave:

- **The statistics of a freshly filled database are old.** A database that has just been filled keeps the optimizer statistics from before the fill, so the plan a capture sees can differ from the plan EXPLAIN reads on the analyzed database (`GET /shelves/{slug}`: the capture saw an `index` scan of `activities`, EXPLAIN and the measurement a `ref` of 4 rows). The new capture runs `ANALYZE TABLE` on every table of each database right after it is built and before a route is sent, then sets `STATS_AUTO_RECALC=0` on them (`bin/capture --analyze-after-build=on`; the option is off by default and `bin/measure` does not pass it).
- **The capture stopped at 10,000 rows, in the middle of a plan change** (`index_merge` begins between 1,000 and 10,000 rows for the shared statement of `GET /books/{slug}` and the ZIP export). The capture now has the row counts 100, 1,000, 10,000 and 30,000.

The same eight routes of the same BookStack checkout, the same generator profile, the same static analysis and the same EXPLAIN files as `estimate-r2/` are used; only the capture differs. The earlier capture and every earlier record are kept as they were.

## The statistics state of the capture databases

`statistics.json` holds, for each database of the capture, the number of tables analyzed and set to `STATS_AUTO_RECALC=0` (41 of 41 at every count) and the tables whose statistics row count (`information_schema.TABLES.table_rows`, statistics cache off) changed under `ANALYZE TABLE`. At 100 and 1,000 rows 3 tables changed; at 10,000 and 30,000 rows 15 did (for example `entities` 9,956 to 10,167 and `joint_permissions` 90,000 to 97,252 at 10,000 rows). The `last_update` column of `mysql.innodb_table_stats` is not read: the measurement user cannot read the `mysql` schema and its privileges are not widened for this, and the capture stack is thrown away at the end. The server is MySQL 8.4.7 with `innodb_stats_persistent=1` and a sample of 20 pages. A capture of the same inputs run a second time gave the same `capture.json` except three timestamps in the bound values of two statements, and the same estimate bytes for all six files.

## The two estimates

- **no rule**: `bin/estimate` as of the parent of commit `f029e9b` (a fit above exponent 1 goes on along its own curve beyond the largest capture size).
- **rule**: `bin/estimate` as of the commit that added the tangent rule and later (the fit goes on along its tangent at the largest size; the curve is kept as the upper side).

At 100,000 rows the largest capture size is 30,000 rows, so the rule acts above 30,000 rows; at 10,000 and 30,000 rows both estimates are the same (at or below the largest size nothing changes).

## Rows read: estimate ÷ measured (point)

Measured rows are the cold in-process request of `../diagnosis/per-request/` (median of three). "Old capture" is `estimate/`, `estimate-30k/`, `estimate-10k/` (no rule) and `estimate-r2/` (rule).

| rows | route | rows measured | old capture, no rule | old capture, rule | new capture, no rule | new capture, rule | new capture, rule: upper side |
|---|---|---|---|---|---|---|---|
| 10,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | 222 | 1.01 | 1.01 | 1.01 | 1.01 | 1.01 |
| 10,000 | `/books/{bookSlug}/export/zip` | 53,698 | 1.00 | 1.00 | 1.55 | 1.55 | 15.99 |
| 10,000 | `/books/{bookSlug}/page/{pageSlug}/references` | 225 | 1.00 | 1.00 | 1.02 | 1.02 | 2.60 |
| 10,000 | `/books/{slug}` | 78,460 | 1.00 | 1.00 | 1.59 | 1.59 | 26.98 |
| 10,000 | `/books/{slug}/references` | 217 | 1.01 | 1.01 | 1.00 | 1.00 | 1.00 |
| 10,000 | `/images/edit/{id}` | 199 | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 |
| 10,000 | `/oidc/callback` | 0 | n/a | n/a | n/a | n/a | n/a |
| 10,000 | `/shelves/{slug}` | 237 | 43.19 | 43.19 | 1.11 | 1.11 | 1.11 |
| 30,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | 222 | 1.01 | 1.01 | 1.01 | 1.01 | 1.01 |
| 30,000 | `/books/{bookSlug}/export/zip` | 250,696 | 0.98 | 0.78 | 1.00 | 1.00 | 3.70 |
| 30,000 | `/books/{bookSlug}/page/{pageSlug}/references` | 229 | 0.98 | 0.98 | 1.01 | 1.01 | 2.59 |
| 30,000 | `/books/{slug}` | 378,454 | 1.04 | 0.79 | 1.00 | 1.00 | 6.78 |
| 30,000 | `/books/{slug}/references` | 217 | 1.01 | 1.01 | 1.00 | 1.00 | 1.00 |
| 30,000 | `/images/edit/{id}` | 199 | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 |
| 30,000 | `/oidc/callback` | 0 | n/a | n/a | n/a | n/a | n/a |
| 30,000 | `/shelves/{slug}` | 236 | 128.12 | 128.12 | 1.12 | 1.12 | 1.12 |
| 100,000 | `/books/{bookSlug}/chapter/{chapterSlug}/references` | 222 | 1.01 | 1.01 | 1.01 | 1.01 | 1.01 |
| 100,000 | `/books/{bookSlug}/export/zip` | 835,196 | 1.78 | 0.83 | 1.01 | 1.01 | 2.04 |
| 100,000 | `/books/{bookSlug}/page/{pageSlug}/references` | 229 | 0.98 | 0.98 | 1.02 | 1.02 | 1.02 |
| 100,000 | `/books/{slug}` | 1,260,454 | 2.02 | 0.85 | 1.02 | 1.01 | 1.02 |
| 100,000 | `/books/{slug}/references` | 217 | 1.01 | 1.01 | 1.00 | 1.00 | 1.00 |
| 100,000 | `/images/edit/{id}` | 199 | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 |
| 100,000 | `/oidc/callback` | 0 | n/a | n/a | n/a | n/a | n/a |
| 100,000 | `/shelves/{slug}` | 236 | 424.73 | 424.73 | 1.27 | 1.15 | 1.27 |

Statements (estimate ÷ measured) stay between 1.00 and 1.05 for all routes and counts in the new capture; `GET /books/{slug}` is 45.3, 46.2 and 47.2 against 45 measured.

## What it shows

- **Where the miss was, it is gone at 100,000 rows.** `GET /books/{slug}` 1.02 (no rule) / 1.01 (rule), the ZIP export 1.01 / 1.01, `GET /shelves/{slug}` 1.27 / 1.15 (it was 425 times). The five small routes are unchanged (1.00 to 1.02 at every count).
- **The tangent rule is almost inert on this capture.** The shared statement now fits with an exponent of 1.01, so the tangent and the curve differ by 0.4% to 0.8% at 100,000 rows. The rule changes `GET /shelves/{slug}` at 100,000 rows (1.27 to 1.15) and `GET /books/{slug}` (1.02 to 1.01); every other cell is the same to two digits. Before the new capture it moved the two routes from 2.02 and 1.78 to 0.85 and 0.83 and from 1.04 and 0.98 to 0.79 and 0.78 at 30,000 rows.
- **The point at 10,000 rows got worse for two routes.** `GET /books/{slug}` 1.59 and the ZIP export 1.55 (both before: 1.00). 10,000 rows is now an interior point of one curve fitted through four sizes that do not lie on one power law; the reading that this is the cause was not tested. The upper sides at 10,000 rows (16 and 27 times) are above the measured by a large margin and are not a quantity this record uses.
- **Every cell here is on counts that were already seen.** 10,000 and 30,000 rows are sizes of the capture itself (the 30,000-row point is inside the fit, so its agreement is a biased comparison), and 100,000 rows was looked at when the rule was chosen. None of this tests the estimate; a size not looked at does.
- **Database CPU time is not repaired by this.** With `c_q` and `c_row` of the committed calibration, `GET /books/{slug}` is 2.5, 0.75 and 2.5 seconds against 0.103, 0.342 and 1.13 measured (2.49, 2.20, 2.22 times) at 10,000, 30,000 and 100,000 rows, the ZIP export 1.07, 1.07 and 1.41 times. Rows and statements agree; what a row or a statement costs is the calibration's side.

## Choosing between the two

The rule fixed before the new files were made, as the sum over the three counts of `|ln(estimate ÷ measured rows)|` per route, the smaller side preferred unless the side with the rule has more counts whose point is more than 20% below the measured:

| | sum over the 7 routes with rows | counts below 0.8 |
| --- | --- | --- |
| no rule | 1.458 | 0 |
| rule | 1.342 | 0 |

Without `GET /shelves/{slug}` the sums are 1.009 and 0.997: the two are close, and the difference is carried by `GET /shelves/{slug}` at 100,000 rows. The side with the rule is the one kept (it is the side the records already carry).

## How the files were made

```
bin/capture --app=<bookstack> --static=<static.json> --out=<out> --app-name=bookstack --profile=bookstack \
  --scales=100,1000,10000,30000 --request-timeout=300 --n-tables=entities,images,references \
  --only=<the eight routes> --analyze-after-build=on
bin/predict --fits=docs/research/extrapolation-data-2/bookstack/fits.json --at=<N> > predictions.json   # read for route, axis and table only
bin/estimate --capture=<out>/capture.json --explain=<explain.json for N> \
  --candidate=H1_RANGE --at=<N> --predictions=predictions.json --axes-only --with-formulas > estimate.json
```

The capture runs in its own sealed stack (internal network, preflight checked), the lab stack is not used. The same EXPLAIN files as `estimate-r2/` are used (100,000 rows against 10,000; 30,000 against 3,000; 10,000 against 1,000). The files `rule/` and `no-rule/` are the output of the code described above; the `capture.json` is kept outside the repository, as before.

| Input | SHA-256 |
| --- | --- |
| `capture.json` (new) | `69f08fcad32bdd31367747d8231dab69df32596a837876253169eda88807d839` |
| `explain.json`, 100,000 rows | `9f95a7a52b430a1bf2d550ad235d13006388110c31d80155fd1a9fc463a133da` |
| `explain.json`, 30,000 rows (against 3,000) | `dc865842ff72f79c970901ce4a428e91931f2b1506b744f255b627f70c57a050` |
| `explain.json`, 10,000 rows | `1de82e71834365197d5420f28402be0958f7cd1db2f1e21c4b16cad6872de0e9` |

| Output | SHA-256 |
| --- | --- |
| `rule/100k/estimate.json` | `d18fecb1b7db68314b25be20a383654da81ed8c66674cd9af4fd904bea61a81b` |
| `rule/30k/estimate.json` | `e5c9f9137616dad0700d24e7ad314451f8739c1ded3b2b18d1bb48bde59f4f7f` |
| `rule/10k/estimate.json` | `a04b34a8d90bc5685a66952c151577e4769e8c20db169d0b42e2b7b28ce7c662` |
| `no-rule/100k/estimate.json` | `399ca3e49b1c21e307dd95c651aea83d05a7de6272a43fd7173fdfd3dfd7610c` |
| `no-rule/30k/estimate.json` | `3850bc6669c6c8d1c1edd2d24af5be3c0cf3345d7730952d85a7d1bdf1da4c8c` |
| `no-rule/10k/estimate.json` | `dff6da855fbb130564735e5c1973117da9ad96e67a5ee3fb08f90914fe069d54` |
