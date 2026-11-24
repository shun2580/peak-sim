# A verdict (judge_a.py)


Rows 240000. The comparison tool is `extrapolation-data-2/compare_test2.py` (SHA-256 `95da6a9b77bdc5c43a1455c9c1ac9b4f11cb0e966aa1cfeac4e42220e382bb8b`, checked before the run). B decides, A shown (docs/simulator-spec.md 7.1).

## bagisto

**Verdict (rule B): PASS.** queries: 16 of 18 inside +-5% (enough), needs 80%; rows_examined: 15 of 18 inside +-20% (enough), needs 80%

Rule A, shown and not deciding: pass. queries: 16 of 18 inside +-5% (enough), needs 80%; rows_examined: 15 of 18 inside +-20% (enough), needs 80%

| Condition 1a (D153; per application, D215) | Inside | Denominator | Needed | Short |
| --- | --- | --- | --- | --- |
| Goal, unmarked routes, queries (±2%) | 5 | 6 | 5 | 0 |
| Goal, unmarked routes, rows examined (±10%) | 6 | 6 | 5 | 0 |
| Lowest standard, all routes, queries (±5%) | 16 | 18 | 15 | 0 |
| Lowest standard, all routes, rows examined (±20%) | 15 | 18 | 15 | 0 |

Condition 1a for this application is met (all four short numbers are 0).

Condition 3: **5 routes outside the goal** (4 marked, 1 unmarked; marks are the marks of test A). Not measured, with an estimate: 1; no estimate: 0 (counted apart, not misses).

| Route | Marked | Quantity outside the goal and its relative error |
| --- | --- | --- |
| `GET /api/categories` | yes | rows examined 19.98% |
| `GET /api/categories/attributes/{attribute_id}/options` | no | queries 34.64% |
| `GET /api/categories/tree` | yes | queries 12776.22%; rows examined 3183.37% |
| `POST /admin/sales/invoices/create/{order_id}` | yes | rows examined 50.54% |
| `POST /customer/account/orders/cancel/{id}` | yes | rows examined 113.72% |

Not measured, with an estimate: `GET /admin/customers/view/{id}`.

## Both applications together (shown beside the verdicts; not used for them, D71 and D215)

pass. queries: 16 of 18 inside +-5% (enough), needs 80%; rows_examined: 15 of 18 inside +-20% (enough), needs 80%

