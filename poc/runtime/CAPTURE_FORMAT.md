# `capture.json`: the statements of a route, by type, at three small scales

Written by `bin/capture`. It is the input of `bin/explain-at-scale`. The file is a record of one run and is kept outside
the repository. A small example that the tests check line by line is `tests/Unit/Capture/vectors/capture.sample.json`
(built from `tests/Unit/Capture/vectors/capture.input.json`); the code that makes the file is `src/Capture/CaptureBuilder.php`.

## What it holds

For every route, one cold request at each of the scales (default 100, 1,000 and 10,000), with the statements it sent
grouped by **type**: the SQL text with every literal taken out (`StatementShape`). Two texts that differ only in a
value, or in the length of an `in (?, ?, ?)` list, or in the number of tuples of a multi-row `values`, are one type.
Per type and scale it keeps how many times the type ran and how many rows the server read for it. Per type it keeps one
**representative** statement (SQL and bindings) taken from the largest scale, and a formula for the number of runs.

Only the `N` axis (the sweep of the table that grows with the route) is captured. The empty system (scale 0) and the
child-table sweep are measured by the driver but not written here.

## Shape

```
{
  "capture_version": 1,
  "app": "<name>",                      // the --app-name given, or the last part of the app directory; no path
  "profile": "<name>" | null,           // the data profile; a result reached with one is reported apart
  "data": "generator" | "seeder",
  "scales": [100, 1000, 10000],         // ascending
  "representative_scale": 10000,        // the largest of "scales"
  "routes": [                           // sorted by "route"
    {
      "route": "GET /orders/{id}",      // "METHOD uri", the key used by fits.json and the run record
      "method": "GET",
      "uri": "/orders/{id}",
      "status": "captured" | "partial" | "not_captured",
      "reason": null | "scale 10000: timeout (...); ...",     // why the scales that are missing are missing
      "captured_scales": [100, 1000, 10000],
      "totals": {                       // by scale (a string key), only the captured ones
        "100": {
          "statements": 104,            // statements the app sent in the cold request
          "rows_sum": 315,              // rows the server read for them (handler counts), null if not readable
          "cold_rows_examined": 315,    // the figure of the attempt itself
          "unaccounted": 0,             // cold_rows_examined - rows_sum
          "unread_events": 0
        }
      },
      "statement_types": [              // sorted by "pattern"
        {
          "id": "seba30d72d4",          // "s" + the first 10 hex digits of sha1(pattern); the same type has the same id in every run
          "kind": "select",             // first word of the statement, lower case: select / update / delete / insert / ...
          "pattern": "select * from `items` where `order_id` = ?",   // the type: no literal, lists collapsed to (?)
          "representative": {
            "scale": 10000,             // the scale it was taken from: the largest captured scale that ran this type
            "at_representative_scale": true,
            "sql": "select * from `items` where `order_id` = ?",    // as the app sent it
            "bindings": [7]             // the values of the first run of that text, in order of the "?"; secrets are "[redacted]"
          },
          "per_scale": {                // by scale (a string key), only the captured ones; 0 runs where the type did not run is not written
            "100": {
              "count": 100,             // runs of this type (all its texts added up)
              "texts": 1,               // how many different texts (lists of other lengths) were behind it
              "rows_sum": 300,          // rows read for all the runs; null when the counters could not be read
              "rows_min": 3,            // fewest and most rows read by one run
              "rows_max": 3,
              "unread": 0               // runs whose rows could not be read (left out of the sums)
            }
          },
          "count_fit": {                // how the number of runs grows with N (CostFit: y = a + b * N^k, a,b >= 0, 0 <= k <= 3)
            "fit_status": "ok",         // or "insufficient_scales" (with "reason") when fewer than 3 scales were captured
            "a": 0.0, "b": 1.0, "k": 1.0, "sse": 0.0,
            "scales": [100, 1000, 10000]
          }
        }
      ]
    }
  ]
}
```

Notes for a reader of the file:

- The value of the formula at `N` is `a + b * N^k` (`CostFit::evaluate`). A type that did not run at a scale counts as 0
  there: the series of a type has one number for every captured scale.
- `representative.sql` has its `?` placeholders; `bindings` fills them in order. When the representative is a text with a
  longer or shorter list than the other texts of its type, that is the list of the largest scale: the text with the most
  rows read, then the most runs, then the first.
- `status: "partial"`: some scales were captured and some were not (the reason says which and why); the types and their
  representatives come from the scales that were captured, and the formulas are not fitted (`insufficient_scales`) unless
  three scales are there. `"not_captured"`: no scale was; `statement_types` is empty.
- A route with a static analysis that fixes the number of runs is compared with these formulas by the next step, not here.

## Secrets

A bound value whose column name matches `SecretBindings::PATTERN` (password, token, secret, api key, credential, recovery
codes, private key, otp, two-factor, authorization, bearer, cookie, signature, salt, cvv) is written as `[redacted]`:
in `bindings`, and in the tap records the file is built from. The column is found from the SQL (`col = ?`, `col in (...)`,
`between`, `like`, `update ... set col = ?`, `insert into t (cols) values (...)`). A value whose column cannot be found is
redacted too when the statement names a secret column anywhere. A string literal written in the SQL next to a secret
column is replaced by `'[redacted]'`. A plan asked for with a redacted value is still a plan for an equality on that column.
