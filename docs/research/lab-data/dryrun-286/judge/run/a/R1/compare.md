# Comparison at N = 30,000

Rule B decides; rule A is shown beside it. Lowest standard: queries +-5%, rows examined +-20%. Goal: queries +-2%, rows examined +-10%. Peak memory +-30% (reported only).

## fixtures

**Rule B (decides): indeterminate**

- queries: the denominator has 3 routes, fewer than 5
- queries: 19 of 22 routes with an estimate could not be measured, more than 20%
- rows_examined: the denominator has 3 routes, fewer than 5
- rows_examined: 19 of 22 routes with an estimate could not be measured, more than 20%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 3 | 3 | 100.00% | 3 | 100.00% | 19 | 2 |
| all | rows_examined | 3 | 3 | 100.00% | 3 | 100.00% | 19 | 2 |
| all | peak_memory | 3 | 3 | 100.00% | 3 | 100.00% | 21 | 0 |
| unmarked | queries | 3 | 3 | 100.00% | 3 | 100.00% | 19 | 2 |
| unmarked | rows_examined | 3 | 3 | 100.00% | 3 | 100.00% | 19 | 2 |
| unmarked | peak_memory | 3 | 3 | 100.00% | 3 | 100.00% | 21 | 0 |
| marked | queries | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | rows_examined | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | peak_memory | 0 | 0 | n/a | 0 | n/a | 0 | 0 |

**Rule A (shown, does not decide): indeterminate**

- queries: the denominator has 3 routes, fewer than 5
- queries: 19 of 22 routes with an estimate could not be measured, more than 20%
- rows_examined: the denominator has 3 routes, fewer than 5
- rows_examined: 19 of 22 routes with an estimate could not be measured, more than 20%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 3 | 3 | 100.00% | 3 | 100.00% | 19 | 2 |
| all | rows_examined | 3 | 3 | 100.00% | 3 | 100.00% | 19 | 2 |
| all | peak_memory | 3 | 3 | 100.00% | 3 | 100.00% | 21 | 0 |
| unmarked | queries | 3 | 3 | 100.00% | 3 | 100.00% | 19 | 2 |
| unmarked | rows_examined | 3 | 3 | 100.00% | 3 | 100.00% | 19 | 2 |
| unmarked | peak_memory | 3 | 3 | 100.00% | 3 | 100.00% | 21 | 0 |
| marked | queries | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | rows_examined | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | peak_memory | 0 | 0 | n/a | 0 | n/a | 0 | 0 |

## bookstack

**Rule B (decides): indeterminate**

- queries: 27 of 35 routes with an estimate could not be measured, more than 20%
- rows_examined: 27 of 35 routes with an estimate could not be measured, more than 20%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 8 | 7 | 87.50% | 7 | 87.50% | 27 | 1 |
| all | rows_examined | 8 | 7 | 87.50% | 7 | 87.50% | 27 | 1 |
| all | peak_memory | 8 | 8 | 100.00% | 8 | 100.00% | 27 | 1 |
| unmarked | queries | 4 | 4 | 100.00% | 4 | 100.00% | 23 | 0 |
| unmarked | rows_examined | 4 | 3 | 75.00% | 3 | 75.00% | 23 | 0 |
| unmarked | peak_memory | 4 | 4 | 100.00% | 4 | 100.00% | 23 | 0 |
| marked | queries | 4 | 3 | 75.00% | 3 | 75.00% | 4 | 1 |
| marked | rows_examined | 4 | 4 | 100.00% | 4 | 100.00% | 4 | 1 |
| marked | peak_memory | 4 | 4 | 100.00% | 4 | 100.00% | 4 | 1 |

**Rule A (shown, does not decide): indeterminate**

- queries: 27 of 35 routes with an estimate could not be measured, more than 20%
- rows_examined: 27 of 35 routes with an estimate could not be measured, more than 20%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 8 | 7 | 87.50% | 7 | 87.50% | 27 | 1 |
| all | rows_examined | 8 | 5 | 62.50% | 5 | 62.50% | 27 | 1 |
| all | peak_memory | 8 | 8 | 100.00% | 8 | 100.00% | 27 | 1 |
| unmarked | queries | 4 | 4 | 100.00% | 4 | 100.00% | 23 | 0 |
| unmarked | rows_examined | 4 | 3 | 75.00% | 3 | 75.00% | 23 | 0 |
| unmarked | peak_memory | 4 | 4 | 100.00% | 4 | 100.00% | 23 | 0 |
| marked | queries | 4 | 3 | 75.00% | 3 | 75.00% | 4 | 1 |
| marked | rows_examined | 4 | 2 | 50.00% | 2 | 50.00% | 4 | 1 |
| marked | peak_memory | 4 | 4 | 100.00% | 4 | 100.00% | 4 | 1 |

## both apps

**Rule B (decides): indeterminate**

- queries: 46 of 57 routes with an estimate could not be measured, more than 20%
- rows_examined: 46 of 57 routes with an estimate could not be measured, more than 20%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 11 | 10 | 90.91% | 10 | 90.91% | 46 | 3 |
| all | rows_examined | 11 | 10 | 90.91% | 10 | 90.91% | 46 | 3 |
| all | peak_memory | 11 | 11 | 100.00% | 11 | 100.00% | 48 | 1 |
| unmarked | queries | 7 | 7 | 100.00% | 7 | 100.00% | 42 | 2 |
| unmarked | rows_examined | 7 | 6 | 85.71% | 6 | 85.71% | 42 | 2 |
| unmarked | peak_memory | 7 | 7 | 100.00% | 7 | 100.00% | 44 | 0 |
| marked | queries | 4 | 3 | 75.00% | 3 | 75.00% | 4 | 1 |
| marked | rows_examined | 4 | 4 | 100.00% | 4 | 100.00% | 4 | 1 |
| marked | peak_memory | 4 | 4 | 100.00% | 4 | 100.00% | 4 | 1 |

**Rule A (shown, does not decide): indeterminate**

- queries: 46 of 57 routes with an estimate could not be measured, more than 20%
- rows_examined: 46 of 57 routes with an estimate could not be measured, more than 20%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 11 | 10 | 90.91% | 10 | 90.91% | 46 | 3 |
| all | rows_examined | 11 | 8 | 72.73% | 8 | 72.73% | 46 | 3 |
| all | peak_memory | 11 | 11 | 100.00% | 11 | 100.00% | 48 | 1 |
| unmarked | queries | 7 | 7 | 100.00% | 7 | 100.00% | 42 | 2 |
| unmarked | rows_examined | 7 | 6 | 85.71% | 6 | 85.71% | 42 | 2 |
| unmarked | peak_memory | 7 | 7 | 100.00% | 7 | 100.00% | 44 | 0 |
| marked | queries | 4 | 3 | 75.00% | 3 | 75.00% | 4 | 1 |
| marked | rows_examined | 4 | 2 | 50.00% | 2 | 50.00% | 4 | 1 |
| marked | peak_memory | 4 | 4 | 100.00% | 4 | 100.00% | 4 | 1 |

## fixtures: largest errors (rule B, queries and rows examined)

| # | Route | Quantity | Marked | Predicted | Measured | Relative error | Lowest | Goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `GET /ng/first-or-create-in-loop` | queries | no | 60,001 | 60,001 | 0.00% | yes | yes |
| 2 | `GET /ng/first-or-create-in-loop` | rows_examined | no | 60,003 | 60,003 | 0.00% | yes | yes |
| 3 | `GET /ng/n-plus-one` | queries | no | 30,001 | 30,001 | 0.00% | yes | yes |
| 4 | `GET /ng/n-plus-one` | rows_examined | no | 60,003 | 60,003 | 0.00% | yes | yes |
| 5 | `GET /ok/eager` | queries | no | 2 | 2 | 0.00% | yes | yes |
| 6 | `GET /ok/eager` | rows_examined | no | 30,026 | 30,026 | 0.00% | yes | yes |

## fixtures: marked routes, the point and the upper side against the cold values (rows examined)

No marked route.

## fixtures: failed cold tries by class

| Which tries | timeout | 422 | 500 | exception | other |
| --- | ---: | ---: | ---: | ---: | ---: |
| used (rule B drops them; a timeout on a marked route makes it not measured) | 0 | 0 | 1 | 0 | 0 |
| superseded by a re-run (not used) | 0 | 0 | 0 | 0 | 0 |

| Route | Marked | Kind | State | Class | Status | Detail |
| --- | --- | --- | --- | --- | ---: | --- |
| `POST /ok/bulk` | no | measure | used | 500 | 500 | Illuminate\Database\QueryException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholde |

Marked routes not measured under rule B because of a timeout (600 seconds or more): none

Timeouts recorded under another class name (counted as timeout by their detail): none

## fixtures: outside a denominator

| Route | Quantity | Category | Reason |
| --- | --- | --- | --- |
| `GET /hidden/appends` | queries | unmeasured | no cold value at the size |
| `GET /hidden/appends` | rows_examined | unmeasured | no cold value at the size |
| `GET /hidden/appends` | peak_memory | unmeasured | no cold value at the size |
| `GET /hidden/blade` | queries | unmeasured | no cold value at the size |
| `GET /hidden/blade` | rows_examined | unmeasured | no cold value at the size |
| `GET /hidden/blade` | peak_memory | unmeasured | no cold value at the size |
| `GET /hidden/cache` | queries | unmeasured | no cold value at the size |
| `GET /hidden/cache` | rows_examined | unmeasured | no cold value at the size |
| `GET /hidden/cache` | peak_memory | unmeasured | no cold value at the size |
| `GET /hidden/conditional-binding` | queries | unmeasured | no cold value at the size |
| `GET /hidden/conditional-binding` | rows_examined | unmeasured | no cold value at the size |
| `GET /hidden/conditional-binding` | peak_memory | unmeasured | no cold value at the size |
| `GET /hidden/cursor` | queries | unmeasured | no cold value at the size |
| `GET /hidden/cursor` | rows_examined | unmeasured | no cold value at the size |
| `GET /hidden/cursor` | peak_memory | unmeasured | no cold value at the size |
| `GET /hidden/model-with` | queries | unmeasured | no cold value at the size |
| `GET /hidden/model-with` | rows_examined | unmeasured | no cold value at the size |
| `GET /hidden/model-with` | peak_memory | unmeasured | no cold value at the size |
| `GET /hidden/resource` | queries | unmeasured | no cold value at the size |
| `GET /hidden/resource` | rows_examined | unmeasured | no cold value at the size |
| `GET /hidden/resource` | peak_memory | unmeasured | no cold value at the size |
| `GET /ng/collection-filter` | queries | unmeasured | no cold value at the size |
| `GET /ng/collection-filter` | rows_examined | unmeasured | no cold value at the size |
| `GET /ng/collection-filter` | peak_memory | unmeasured | no cold value at the size |
| `GET /ng/count-in-loop` | queries | unmeasured | no cold value at the size |
| `GET /ng/count-in-loop` | rows_examined | unmeasured | no cold value at the size |
| `GET /ng/count-in-loop` | peak_memory | unmeasured | no cold value at the size |
| `GET /ng/get-then-count` | queries | unmeasured | no cold value at the size |
| `GET /ng/get-then-count` | rows_examined | unmeasured | no cold value at the size |
| `GET /ng/get-then-count` | peak_memory | unmeasured | no cold value at the size |
| `GET /ng/layered` | queries | unmeasured | no cold value at the size |
| `GET /ng/layered` | rows_examined | unmeasured | no cold value at the size |
| `GET /ng/layered` | peak_memory | unmeasured | no cold value at the size |
| `GET /ng/nested` | queries | no_estimate | estimate incomplete (partial) |
| `GET /ng/nested` | rows_examined | no_estimate | estimate incomplete (partial) |
| `GET /ng/nested` | peak_memory | unmeasured | no cold value at the size |
| `GET /ng/save-in-loop` | queries | unmeasured | no cold value at the size |
| `GET /ng/save-in-loop` | rows_examined | unmeasured | no cold value at the size |
| `GET /ng/save-in-loop` | peak_memory | unmeasured | no cold value at the size |
| `GET /ng/service` | queries | unmeasured | no cold value at the size |
| `GET /ng/service` | rows_examined | unmeasured | no cold value at the size |
| `GET /ng/service` | peak_memory | unmeasured | no cold value at the size |
| `GET /ng/unbounded` | queries | unmeasured | no cold value at the size |
| `GET /ng/unbounded` | rows_examined | unmeasured | no cold value at the size |
| `GET /ng/unbounded` | peak_memory | unmeasured | no cold value at the size |
| `GET /ok/chunk` | queries | unmeasured | no cold value at the size |
| `GET /ok/chunk` | rows_examined | unmeasured | no cold value at the size |
| `GET /ok/chunk` | peak_memory | unmeasured | no cold value at the size |
| `GET /ok/lazy` | queries | unmeasured | no cold value at the size |
| `GET /ok/lazy` | rows_examined | unmeasured | no cold value at the size |
| `GET /ok/lazy` | peak_memory | unmeasured | no cold value at the size |
| `GET /ok/paginate` | queries | unmeasured | no cold value at the size |
| `GET /ok/paginate` | rows_examined | unmeasured | no cold value at the size |
| `GET /ok/paginate` | peak_memory | unmeasured | no cold value at the size |
| `GET /ok/with-count` | queries | unmeasured | no cold value at the size |
| `GET /ok/with-count` | rows_examined | unmeasured | no cold value at the size |
| `GET /ok/with-count` | peak_memory | unmeasured | no cold value at the size |
| `GET /unknown/dynamic` | queries | unmeasured | no cold value at the size |
| `GET /unknown/dynamic` | rows_examined | unmeasured | no cold value at the size |
| `GET /unknown/dynamic` | peak_memory | unmeasured | no cold value at the size |
| `POST /ok/bulk` | queries | no_estimate | estimate incomplete (partial) |
| `POST /ok/bulk` | rows_examined | no_estimate | estimate incomplete (partial) |
| `POST /ok/bulk` | peak_memory | unmeasured | no cold value at the size; failed tries: 500 |

## bookstack: largest errors (rule B, queries and rows examined)

| # | Route | Quantity | Marked | Predicted | Measured | Relative error | Lowest | Goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `PUT /shelves/{slug}` | rows_examined | no | 35,037.7 | 10,730 | 226.54% | no | no |
| 2 | `GET /shelves/{slug}` | rows_examined | yes | 259.0 | 236 | 9.73% | yes | yes |
| 3 | `GET /books/{slug}` | queries | yes | 48.5 | 45 | 7.68% | no | no |
| 4 | `GET /books/{slug}/references` | rows_examined | no | 11,137.1 | 10,710 | 3.99% | yes | yes |
| 5 | `PUT /books/{slug}` | rows_examined | yes | 461,403.7 | 448,734 | 2.82% | yes | yes |
| 6 | `GET /shelves/{slug}` | queries | yes | 18.3 | 18 | 1.61% | yes | yes |
| 7 | `GET /books/{slug}` | rows_examined | yes | 378,974.8 | 378,457 | 0.14% | yes | yes |
| 8 | `DELETE /shelves/{slug}` | rows_examined | no | 239,109.8 | 239,015 | 0.04% | yes | yes |
| 9 | `POST /settings/maintenance/regenerate-references` | queries | yes | 608.0 | 608 | 0.01% | yes | yes |
| 10 | `PUT /books/{slug}` | queries | yes | 70,508.7 | 70,511 | 0.00% | yes | yes |

## bookstack: marked routes, the point and the upper side against the cold values (rows examined)

| Route | Point (H1) | Upper side (H2) | Cold min | Cold max | Cold values (count of 4) | Point vs cold range | Upper vs cold range | Range enclosed by point and upper |
| --- | ---: | ---: | ---: | ---: | --- | --- | --- | --- |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | 198,497.2 | 866,463.1 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /books/{bookSlug}/export/zip` | 252,031.7 | 913,723.4 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /books/{bookSlug}/page/{pageSlug}` | 198,501.2 | 866,819.4 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /books/{slug}` | 378,974.8 | 2,512,979.0 | 234,454 | 378,457 | 234,454, 234,454, 378,457 (3/4) | above | above | no |
| `GET /shelves/{slug}` | 259.0 | 259.0 | 236 | 236 | 236, 236, 236 (3/4) | above | above | no |
| `POST /books/{bookSlug}/copy` | n/a | 0 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `POST /books/{bookSlug}/draft/{pageId}` | 102,393.9 | 379,574.9 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `POST /settings/maintenance/regenerate-references` | 4,503,431.6 | 54,339,291.3 | 4,503,507 | 4,503,507 | 4,503,507, 4,503,507, 4,503,507 (3/4) | below | above | yes |
| `PUT /books/{slug}` | 461,403.7 | 1,480,032.8 | 382,725 | 448,734 | 382,733, 382,725, 448,734 (3/4) | above | above | no |


### bookstack: every try of the marked routes (which were used)

| Route | Kind | Run | State | Result | Rows examined |
| --- | --- | --- | --- | --- | ---: |
| `GET /books/{slug}` | measure | measurement run 1 | used | ok | 234,454 |
| `GET /books/{slug}` | variance | variance run 1 | used | ok | 234,454 |
| `GET /books/{slug}` | variance | variance run 1 | used | ok | 378,457 |
| `GET /shelves/{slug}` | measure | measurement run 1 | used | ok | 236 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 236 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 236 |
| `POST /settings/maintenance/regenerate-references` | measure | measurement run 1 | used | ok | 4,503,507 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 4,503,507 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 4,503,507 |
| `PUT /books/{slug}` | measure | measurement run 1 | used | ok | 382,733 |
| `PUT /books/{slug}` | variance | variance run 1 | used | ok | 382,725 |
| `PUT /books/{slug}` | variance | variance run 1 | used | ok | 448,734 |

**Marked routes with fewer than four cold values (rule B takes the largest of those that exist; the rules do not say what to do when one is missing):** `GET /books/{slug}` (3/4), `GET /shelves/{slug}` (3/4), `POST /settings/maintenance/regenerate-references` (3/4), `PUT /books/{slug}` (3/4)

## bookstack: failed cold tries by class

| Which tries | timeout | 422 | 500 | exception | other |
| --- | ---: | ---: | ---: | ---: | ---: |
| used (rule B drops them; a timeout on a marked route makes it not measured) | 0 | 0 | 0 | 0 | 0 |
| superseded by a re-run (not used) | 0 | 0 | 0 | 0 | 0 |

Marked routes not measured under rule B because of a timeout (600 seconds or more): none

Timeouts recorded under another class name (counted as timeout by their detail): none

## bookstack: outside a denominator

| Route | Quantity | Category | Reason |
| --- | --- | --- | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | queries | unmeasured | no cold value at the size |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | rows_examined | unmeasured | no cold value at the size |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | peak_memory | unmeasured | no cold value at the size |
| `DELETE /books/{bookSlug}/draft/{pageId}` | queries | unmeasured | no cold value at the size |
| `DELETE /books/{bookSlug}/draft/{pageId}` | rows_examined | unmeasured | no cold value at the size |
| `DELETE /books/{bookSlug}/draft/{pageId}` | peak_memory | unmeasured | no cold value at the size |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | queries | unmeasured | no cold value at the size |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | rows_examined | unmeasured | no cold value at the size |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | peak_memory | unmeasured | no cold value at the size |
| `DELETE /books/{id}` | queries | unmeasured | no cold value at the size |
| `DELETE /books/{id}` | rows_examined | unmeasured | no cold value at the size |
| `DELETE /books/{id}` | peak_memory | unmeasured | no cold value at the size |
| `DELETE /import/{id}` | queries | unmeasured | no cold value at the size |
| `DELETE /import/{id}` | rows_examined | unmeasured | no cold value at the size |
| `DELETE /import/{id}` | peak_memory | unmeasured | no cold value at the size |
| `DELETE /settings/maintenance/cleanup-images` | queries | unmeasured | no cold value at the size |
| `DELETE /settings/maintenance/cleanup-images` | rows_examined | unmeasured | no cold value at the size |
| `DELETE /settings/maintenance/cleanup-images` | peak_memory | unmeasured | no cold value at the size |
| `DELETE /settings/sorting/rules/{id}` | queries | unmeasured | no cold value at the size |
| `DELETE /settings/sorting/rules/{id}` | rows_examined | unmeasured | no cold value at the size |
| `DELETE /settings/sorting/rules/{id}` | peak_memory | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | queries | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | rows_examined | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | peak_memory | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | queries | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | rows_examined | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | peak_memory | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/copy` | queries | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/copy` | rows_examined | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/copy` | peak_memory | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/draft/{pageId}` | queries | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/draft/{pageId}` | rows_examined | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/draft/{pageId}` | peak_memory | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/export/zip` | queries | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/export/zip` | rows_examined | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/export/zip` | peak_memory | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/page/{pageSlug}` | queries | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/page/{pageSlug}` | rows_examined | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/page/{pageSlug}` | peak_memory | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | queries | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | rows_examined | unmeasured | no cold value at the size |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | peak_memory | unmeasured | no cold value at the size |
| `GET /images/edit/{id}` | queries | unmeasured | no cold value at the size |
| `GET /images/edit/{id}` | rows_examined | unmeasured | no cold value at the size |
| `GET /images/edit/{id}` | peak_memory | unmeasured | no cold value at the size |
| `GET /oidc/callback` | queries | unmeasured | no cold value at the size |
| `GET /oidc/callback` | rows_examined | unmeasured | no cold value at the size |
| `GET /oidc/callback` | peak_memory | unmeasured | no cold value at the size |
| `GET /settings/sorting/rules/{id}` | queries | unmeasured | no cold value at the size |
| `GET /settings/sorting/rules/{id}` | rows_examined | unmeasured | no cold value at the size |
| `GET /settings/sorting/rules/{id}` | peak_memory | unmeasured | no cold value at the size |
| `GET /settings/users/{id}` | queries | unmeasured | no cold value at the size |
| `GET /settings/users/{id}` | rows_examined | unmeasured | no cold value at the size |
| `GET /settings/users/{id}` | peak_memory | unmeasured | no cold value at the size |
| `GET /shelves/{slug}/references` | queries | unmeasured | no cold value at the size |
| `GET /shelves/{slug}/references` | rows_examined | unmeasured | no cold value at the size |
| `GET /shelves/{slug}/references` | peak_memory | unmeasured | no cold value at the size |
| `POST /books/{bookSlug}/copy` | queries | no_estimate | estimate incomplete (partial) |
| `POST /books/{bookSlug}/copy` | rows_examined | no_estimate | estimate incomplete (partial) |
| `POST /books/{bookSlug}/copy` | peak_memory | no_estimate | peak_memory not_predicted: scale 10000: not measured |
| `POST /books/{bookSlug}/draft/{pageId}` | queries | unmeasured | no cold value at the size |
| `POST /books/{bookSlug}/draft/{pageId}` | rows_examined | unmeasured | no cold value at the size |
| `POST /books/{bookSlug}/draft/{pageId}` | peak_memory | unmeasured | no cold value at the size |
| `POST /import/{id}` | queries | unmeasured | no cold value at the size |
| `POST /import/{id}` | rows_examined | unmeasured | no cold value at the size |
| `POST /import/{id}` | peak_memory | unmeasured | no cold value at the size |
| `POST /settings/recycle-bin/empty` | queries | unmeasured | no cold value at the size |
| `POST /settings/recycle-bin/empty` | rows_examined | unmeasured | no cold value at the size |
| `POST /settings/recycle-bin/empty` | peak_memory | unmeasured | no cold value at the size |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | queries | unmeasured | no cold value at the size |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | rows_examined | unmeasured | no cold value at the size |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | peak_memory | unmeasured | no cold value at the size |
| `PUT /books/{bookSlug}/page/{pageSlug}` | queries | unmeasured | no cold value at the size |
| `PUT /books/{bookSlug}/page/{pageSlug}` | rows_examined | unmeasured | no cold value at the size |
| `PUT /books/{bookSlug}/page/{pageSlug}` | peak_memory | unmeasured | no cold value at the size |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | queries | unmeasured | no cold value at the size |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | rows_examined | unmeasured | no cold value at the size |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | peak_memory | unmeasured | no cold value at the size |
| `PUT /settings/sorting/rules/{id}` | queries | unmeasured | no cold value at the size |
| `PUT /settings/sorting/rules/{id}` | rows_examined | unmeasured | no cold value at the size |
| `PUT /settings/sorting/rules/{id}` | peak_memory | unmeasured | no cold value at the size |
| `PUT /settings/users/{id}` | queries | unmeasured | no cold value at the size |
| `PUT /settings/users/{id}` | rows_examined | unmeasured | no cold value at the size |
| `PUT /settings/users/{id}` | peak_memory | unmeasured | no cold value at the size |

Failed tries of routes without an estimate row (outside every denominator, they do not affect the verdict):

| Route | Class | Status | Detail |
| --- | --- | ---: | --- |
| `POST /settings/users/create` | 422 (recorded as validation_422) | 422 | The name field is required. (and 3 more errors) / fields: name, email, password, password-confirm |

Measured but without an estimate row: `POST /settings/users/create`

