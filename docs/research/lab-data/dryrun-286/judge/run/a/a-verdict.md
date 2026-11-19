# A verdict (judge_a.py)

No variance directory was given for: fixtures (read as empty: no extra cold laps).

Rows 30000. The comparison tool is `extrapolation-data-2/compare_test2.py` (SHA-256 `95da6a9b77bdc5c43a1455c9c1ac9b4f11cb0e966aa1cfeac4e42220e382bb8b`, checked before the run). B decides, A shown (docs/simulator-spec.md 7.1).

## fixtures

**Verdict (rule B): UNDECIDABLE.** queries: the denominator has 3 routes, fewer than 5; queries: 19 of 22 routes with an estimate could not be measured, more than 20%; rows_examined: the denominator has 3 routes, fewer than 5; rows_examined: 19 of 22 routes with an estimate could not be measured, more than 20%

Rule A, shown and not deciding: undecidable. queries: the denominator has 3 routes, fewer than 5; queries: 19 of 22 routes with an estimate could not be measured, more than 20%; rows_examined: the denominator has 3 routes, fewer than 5; rows_examined: 19 of 22 routes with an estimate could not be measured, more than 20%

| Condition 1a (D153; per application, D215) | Inside | Denominator | Needed | Short |
| --- | --- | --- | --- | --- |
| Goal, unmarked routes, queries (±2%) | 3 | 3 | n/a | n/a (undecidable) |
| Goal, unmarked routes, rows examined (±10%) | 3 | 3 | n/a | n/a (undecidable) |
| Lowest standard, all routes, queries (±5%) | 3 | 3 | n/a | n/a (undecidable) |
| Lowest standard, all routes, rows examined (±20%) | 3 | 3 | n/a | n/a (undecidable) |

The test is undecidable here: no number short is given (D153).

Condition 1a for this application is undecidable.

Condition 3: **0 routes outside the goal** (0 marked, 0 unmarked; marks are the marks of test A). Not measured, with an estimate: 19; no estimate: 2 (counted apart, not misses).

Not measured, with an estimate: `GET /hidden/appends`, `GET /hidden/blade`, `GET /hidden/cache`, `GET /hidden/conditional-binding`, `GET /hidden/cursor`, `GET /hidden/model-with`, `GET /hidden/resource`, `GET /ng/collection-filter`, `GET /ng/count-in-loop`, `GET /ng/get-then-count`, `GET /ng/layered`, `GET /ng/save-in-loop`, `GET /ng/service`, `GET /ng/unbounded`, `GET /ok/chunk`, `GET /ok/lazy`, `GET /ok/paginate`, `GET /ok/with-count`, `GET /unknown/dynamic`.

## bookstack

**Verdict (rule B): UNDECIDABLE.** queries: 27 of 35 routes with an estimate could not be measured, more than 20%; rows_examined: 27 of 35 routes with an estimate could not be measured, more than 20%

Rule A, shown and not deciding: undecidable. queries: 27 of 35 routes with an estimate could not be measured, more than 20%; rows_examined: 27 of 35 routes with an estimate could not be measured, more than 20%

| Condition 1a (D153; per application, D215) | Inside | Denominator | Needed | Short |
| --- | --- | --- | --- | --- |
| Goal, unmarked routes, queries (±2%) | 4 | 4 | n/a | n/a (undecidable) |
| Goal, unmarked routes, rows examined (±10%) | 3 | 4 | n/a | n/a (undecidable) |
| Lowest standard, all routes, queries (±5%) | 7 | 8 | n/a | n/a (undecidable) |
| Lowest standard, all routes, rows examined (±20%) | 7 | 8 | n/a | n/a (undecidable) |

The test is undecidable here: no number short is given (D153).

Condition 1a for this application is undecidable.

Condition 3: **2 routes outside the goal** (1 marked, 1 unmarked; marks are the marks of test A). Not measured, with an estimate: 27; no estimate: 1 (counted apart, not misses).

| Route | Marked | Quantity outside the goal and its relative error |
| --- | --- | --- |
| `GET /books/{slug}` | yes | queries 7.68% |
| `PUT /shelves/{slug}` | no | rows examined 226.54% |

Not measured, with an estimate: `DELETE /books/{bookSlug}/chapter/{chapterSlug}`, `DELETE /books/{bookSlug}/draft/{pageId}`, `DELETE /books/{bookSlug}/page/{pageSlug}`, `DELETE /books/{id}`, `DELETE /import/{id}`, `DELETE /settings/maintenance/cleanup-images`, `DELETE /settings/sorting/rules/{id}`, `GET /books/{bookSlug}/chapter/{chapterSlug}`, `GET /books/{bookSlug}/chapter/{chapterSlug}/references`, `GET /books/{bookSlug}/copy`, `GET /books/{bookSlug}/draft/{pageId}`, `GET /books/{bookSlug}/export/zip`, `GET /books/{bookSlug}/page/{pageSlug}`, `GET /books/{bookSlug}/page/{pageSlug}/references`, `GET /images/edit/{id}`, `GET /oidc/callback`, `GET /settings/sorting/rules/{id}`, `GET /settings/users/{id}`, `GET /shelves/{slug}/references`, `POST /books/{bookSlug}/draft/{pageId}`, `POST /import/{id}`, `POST /settings/recycle-bin/empty`, `PUT /books/{bookSlug}/chapter/{chapterSlug}`, `PUT /books/{bookSlug}/page/{pageSlug}`, `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore`, `PUT /settings/sorting/rules/{id}`, `PUT /settings/users/{id}`.

## Both applications together (shown beside the verdicts; not used for them, D71 and D215)

undecidable. queries: 46 of 57 routes with an estimate could not be measured, more than 20%; rows_examined: 46 of 57 routes with an estimate could not be measured, more than 20%

