# The route list of the Bagisto lab backtest

The routes the lab backtest on Bagisto puts load on, and the tables its guard lets them write. The list was made by
`select_lab_routes.py` from the third capture and committed before any load was put on the lab. It is not changed after
this commit; a different list would be a different test.

The rule is in `docs/simulator-spec.md` section 7.4.7 and in decisions D262 (decisions 2, 3 and 7, and the addendum
on the column).

## What is here

| File | What it is |
| --- | --- |
| `select_lab_routes.py` | makes everything in `list/` from the capture's `capture.json` and `routes.jsonl`, the arrived list, the N table list and the profile |
| `selftest_lab_routes.py` | checks of the script on small made-up captures with answers known by hand |
| `list/selected.tsv` | the routes selected, numbered 1..n in the order of the rule (the numbers D262 decision 3 uses) |
| `list/candidates.tsv` | every candidate with its `rows_sum` at 30,000 rows, whether it writes, and what became of it |
| `list/excluded.tsv` | candidates taken out by the write guard, with the N-list tables they write |
| `list/allowed-tables.tsv` | the tables, by table, that the selected routes write and that are not N-list tables |
| `list/reference-orders.tsv` | the ranks by `cold_rows_examined` and by the `rows.examined` of `routes.jsonl`, beside the rank by `rows_sum`; for reading only, they decide nothing |
| `list/inputs.sha256`, `list/summary.txt` | SHA-256 of the inputs the list was made from, and the counts |

## How the list is made

1. Candidates are the routes of the arrived list (the 19 routes of the committed arrived list) whose method is GET.
2. Write guard: each statement type of a candidate is read from `capture.json`. A statement of kind insert, update, delete or
   replace is a write, and the table it writes is read from its pattern. A candidate that writes a table of the N list (123 tables)
   is taken out. The tables the selected routes write, if any, form the allowed list, by table and not by statement kind.
   A candidate that the profile marks `state_changing`, a write statement of empty kind, a statement kind the rule does not cover
   and a pattern the script cannot read are each a stop.
3. Order: `routes[].totals["30000"].rows_sum`, largest first; ties by the string "METHOD URI". A missing 30,000 entry, a null
   `rows_sum` or an `unread_events` other than 0 is a stop. The first 8 are selected (all, if fewer than 8). Fewer than 6 is a
   stop and nothing is built.

A stop writes nothing and exits with 4.

```bash
python3 -I select_lab_routes.py --capture-dir <capture output dir> --arrived-list <list-arrived.txt> \
  --n-tables <n-tables.txt> --profile <bagisto.php> --out <new dir>
python3 -I selftest_lab_routes.py
```

## What this does not say

The statements the capture recorded for the candidates are all reads, so the allowed list is empty. The capture fixes session,
cache and queue to fakes, so writes to their tables do not appear in it; the lab entry uses the same fakes. A write that the lab
sees and the capture did not is for the guard to refuse, not for this list to change.
