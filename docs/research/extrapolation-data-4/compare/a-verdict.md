# A verdict (judge_a.py)


Rows 240000. The comparison tool is `extrapolation-data-2/compare_test2.py` (SHA-256 `95da6a9b77bdc5c43a1455c9c1ac9b4f11cb0e966aa1cfeac4e42220e382bb8b`, checked before the run). B decides, A shown (docs/simulator-spec.md 7.1).

## fixtures

**Verdict (rule B): PASS.** queries: 21 of 21 inside +-5% (enough), needs 80%; rows_examined: 21 of 21 inside +-20% (enough), needs 80%

Rule A, shown and not deciding: pass. queries: 21 of 21 inside +-5% (enough), needs 80%; rows_examined: 21 of 21 inside +-20% (enough), needs 80%

| Condition 1a (D153; per application, D215) | Inside | Denominator | Needed | Short |
| --- | --- | --- | --- | --- |
| Goal, unmarked routes, queries (±2%) | 21 | 21 | 17 | 0 |
| Goal, unmarked routes, rows examined (±10%) | 21 | 21 | 17 | 0 |
| Lowest standard, all routes, queries (±5%) | 21 | 21 | 17 | 0 |
| Lowest standard, all routes, rows examined (±20%) | 21 | 21 | 17 | 0 |

Condition 1a for this application is met (all four short numbers are 0).

Condition 3: **0 routes outside the goal** (0 marked, 0 unmarked; marks are the marks of test A). Not measured, with an estimate: 1; no estimate: 2 (counted apart, not misses).

Not measured, with an estimate: `GET /ng/first-or-create-in-loop`.

## bookstack

**Verdict (rule B): PASS.** queries: 34 of 34 inside +-5% (enough), needs 80%; rows_examined: 29 of 34 inside +-20% (enough), needs 80%

Rule A, shown and not deciding: fail. queries: 34 of 34 inside +-5% (enough), needs 80%; rows_examined: 26 of 34 inside +-20% (not enough), needs 80%

| Condition 1a (D153; per application, D215) | Inside | Denominator | Needed | Short |
| --- | --- | --- | --- | --- |
| Goal, unmarked routes, queries (±2%) | 15 | 15 | 12 | 0 |
| Goal, unmarked routes, rows examined (±10%) | 13 | 15 | 12 | 0 |
| Lowest standard, all routes, queries (±5%) | 34 | 34 | 28 | 0 |
| Lowest standard, all routes, rows examined (±20%) | 29 | 34 | 28 | 0 |

Condition 1a for this application is met (all four short numbers are 0).

Condition 3: **10 routes outside the goal** (8 marked, 2 unmarked; marks are the marks of test A). Not measured, with an estimate: 1; no estimate: 1 (counted apart, not misses).

| Route | Marked | Quantity outside the goal and its relative error |
| --- | --- | --- |
| `DELETE /books/{id}` | yes | rows examined 15.96% |
| `DELETE /settings/maintenance/cleanup-images` | no | rows examined 13.29% |
| `GET /books/{bookSlug}/page/{pageSlug}` | yes | rows examined 59.54% |
| `GET /books/{slug}/references` | yes | rows examined 49.93% |
| `GET /shelves/{slug}` | yes | queries 4.88%; rows examined 20.23% |
| `GET /shelves/{slug}/references` | yes | rows examined 74.02% |
| `POST /books/{bookSlug}/draft/{pageId}` | no | rows examined 15.73% |
| `POST /settings/maintenance/regenerate-references` | yes | rows examined 14.53% |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | yes | rows examined 15.01% |
| `PUT /shelves/{slug}` | yes | rows examined 45.21% |

Not measured, with an estimate: `GET /books/{slug}`.

## Both applications together (shown beside the verdicts; not used for them, D71 and D215)

pass. queries: 55 of 55 inside +-5% (enough), needs 80%; rows_examined: 50 of 55 inside +-20% (enough), needs 80%

