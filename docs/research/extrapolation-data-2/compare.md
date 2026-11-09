# Comparison at N = 200,000

Rule B decides; rule A is shown beside it. Lowest standard: queries +-5%, rows examined +-20%. Goal: queries +-2%, rows examined +-10%. Peak memory +-30% (reported only).

## fixtures

**Rule B (decides): pass**

- queries: 22 of 22 inside +-5% (enough), needs 80%
- rows_examined: 21 of 22 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| all | rows_examined | 22 | 21 | 95.45% | 19 | 86.36% | 2 | 0 |
| all | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| unmarked | queries | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| unmarked | rows_examined | 22 | 21 | 95.45% | 19 | 86.36% | 2 | 0 |
| unmarked | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| marked | queries | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | rows_examined | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | peak_memory | 0 | 0 | n/a | 0 | n/a | 0 | 0 |

**Rule A (shown, does not decide): pass**

- queries: 22 of 22 inside +-5% (enough), needs 80%
- rows_examined: 21 of 22 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| all | rows_examined | 22 | 21 | 95.45% | 19 | 86.36% | 2 | 0 |
| all | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| unmarked | queries | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| unmarked | rows_examined | 22 | 21 | 95.45% | 19 | 86.36% | 2 | 0 |
| unmarked | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| marked | queries | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | rows_examined | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | peak_memory | 0 | 0 | n/a | 0 | n/a | 0 | 0 |

## bookstack

**Rule B (decides): fail**

- queries: 32 of 34 inside +-5% (enough), needs 80%
- rows_examined: 22 of 34 inside +-20% (not enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 34 | 32 | 94.12% | 31 | 91.18% | 1 | 0 |
| all | rows_examined | 34 | 22 | 64.71% | 22 | 64.71% | 1 | 0 |
| all | peak_memory | 34 | 22 | 64.71% | 22 | 64.71% | 1 | 0 |
| unmarked | queries | 15 | 15 | 100.00% | 15 | 100.00% | 0 | 0 |
| unmarked | rows_examined | 15 | 12 | 80.00% | 12 | 80.00% | 0 | 0 |
| unmarked | peak_memory | 15 | 14 | 93.33% | 14 | 93.33% | 0 | 0 |
| marked | queries | 19 | 17 | 89.47% | 16 | 84.21% | 1 | 0 |
| marked | rows_examined | 19 | 10 | 52.63% | 10 | 52.63% | 1 | 0 |
| marked | peak_memory | 19 | 8 | 42.11% | 8 | 42.11% | 1 | 0 |

**Rule A (shown, does not decide): fail**

- queries: 32 of 34 inside +-5% (enough), needs 80%
- rows_examined: 20 of 34 inside +-20% (not enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 34 | 32 | 94.12% | 31 | 91.18% | 1 | 0 |
| all | rows_examined | 34 | 20 | 58.82% | 20 | 58.82% | 1 | 0 |
| all | peak_memory | 34 | 22 | 64.71% | 22 | 64.71% | 1 | 0 |
| unmarked | queries | 15 | 15 | 100.00% | 15 | 100.00% | 0 | 0 |
| unmarked | rows_examined | 15 | 12 | 80.00% | 12 | 80.00% | 0 | 0 |
| unmarked | peak_memory | 15 | 14 | 93.33% | 14 | 93.33% | 0 | 0 |
| marked | queries | 19 | 17 | 89.47% | 16 | 84.21% | 1 | 0 |
| marked | rows_examined | 19 | 8 | 42.11% | 8 | 42.11% | 1 | 0 |
| marked | peak_memory | 19 | 8 | 42.11% | 8 | 42.11% | 1 | 0 |

## both apps

**Rule B (decides): fail**

- queries: 54 of 56 inside +-5% (enough), needs 80%
- rows_examined: 43 of 56 inside +-20% (not enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 56 | 54 | 96.43% | 53 | 94.64% | 3 | 0 |
| all | rows_examined | 56 | 43 | 76.79% | 41 | 73.21% | 3 | 0 |
| all | peak_memory | 56 | 44 | 78.57% | 44 | 78.57% | 3 | 0 |
| unmarked | queries | 37 | 37 | 100.00% | 37 | 100.00% | 2 | 0 |
| unmarked | rows_examined | 37 | 33 | 89.19% | 31 | 83.78% | 2 | 0 |
| unmarked | peak_memory | 37 | 36 | 97.30% | 36 | 97.30% | 2 | 0 |
| marked | queries | 19 | 17 | 89.47% | 16 | 84.21% | 1 | 0 |
| marked | rows_examined | 19 | 10 | 52.63% | 10 | 52.63% | 1 | 0 |
| marked | peak_memory | 19 | 8 | 42.11% | 8 | 42.11% | 1 | 0 |

**Rule A (shown, does not decide): fail**

- queries: 54 of 56 inside +-5% (enough), needs 80%
- rows_examined: 41 of 56 inside +-20% (not enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 56 | 54 | 96.43% | 53 | 94.64% | 3 | 0 |
| all | rows_examined | 56 | 41 | 73.21% | 39 | 69.64% | 3 | 0 |
| all | peak_memory | 56 | 44 | 78.57% | 44 | 78.57% | 3 | 0 |
| unmarked | queries | 37 | 37 | 100.00% | 37 | 100.00% | 2 | 0 |
| unmarked | rows_examined | 37 | 33 | 89.19% | 31 | 83.78% | 2 | 0 |
| unmarked | peak_memory | 37 | 36 | 97.30% | 36 | 97.30% | 2 | 0 |
| marked | queries | 19 | 17 | 89.47% | 16 | 84.21% | 1 | 0 |
| marked | rows_examined | 19 | 8 | 42.11% | 8 | 42.11% | 1 | 0 |
| marked | peak_memory | 19 | 8 | 42.11% | 8 | 42.11% | 1 | 0 |

## fixtures: largest errors (rule B, queries and rows examined)

| # | Route | Quantity | Marked | Predicted | Measured | Relative error | Lowest | Goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `GET /ok/with-count` | rows_examined | no | 1,086,007.8 | 1,800,003 | 39.67% | no | no |
| 2 | `GET /ok/chunk` | rows_examined | no | 179,741,997.0 | 200,302,002 | 10.26% | yes | no |
| 3 | `GET /ok/lazy` | rows_examined | no | 179,741,997.0 | 200,302,002 | 10.26% | yes | no |
| 4 | `GET /hidden/appends` | queries | no | 200,001 | 200,001 | 0.00% | yes | yes |
| 5 | `GET /hidden/appends` | rows_examined | no | 400,003 | 400,003 | 0.00% | yes | yes |
| 6 | `GET /hidden/blade` | queries | no | 200,001 | 200,001 | 0.00% | yes | yes |
| 7 | `GET /hidden/blade` | rows_examined | no | 400,003 | 400,003 | 0.00% | yes | yes |
| 8 | `GET /hidden/cache` | queries | no | 200,001 | 200,001 | 0.00% | yes | yes |
| 9 | `GET /hidden/cache` | rows_examined | no | 400,003 | 400,003 | 0.00% | yes | yes |
| 10 | `GET /hidden/conditional-binding` | queries | no | 200,001 | 200,001 | 0.00% | yes | yes |

## fixtures: marked routes, the point and the upper side against the cold values (rows examined)

No marked route.

## fixtures: failed cold tries by class

| Which tries | timeout | 422 | 500 | exception | other |
| --- | ---: | ---: | ---: | ---: | ---: |
| used (rule B drops them; a timeout on a marked route makes it not measured) | 1 | 0 | 1 | 0 | 0 |
| superseded by a re-run (not used) | 0 | 0 | 0 | 0 | 0 |

| Route | Marked | Kind | State | Class | Status | Detail |
| --- | --- | --- | --- | --- | ---: | --- |
| `GET /ng/first-or-create-in-loop` | no | measure | used | timeout (600 seconds or more) | 500 | PeakSim\Runtime\Run\RequestTimeout: the request ran longer than 600 seconds |
| `POST /ok/bulk` | no | measure | used | 500 | 500 | Illuminate\Database\QueryException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholde |

Marked routes not measured under rule B because of a timeout (600 seconds or more): none

Timeouts recorded under another class name (counted as timeout by their detail): none

## fixtures: outside a denominator

| Route | Quantity | Category | Reason |
| --- | --- | --- | --- |
| `GET /ng/first-or-create-in-loop` | queries | unmeasured | no cold value at the size; failed tries: timeout (600 seconds or more) |
| `GET /ng/first-or-create-in-loop` | rows_examined | unmeasured | no cold value at the size; failed tries: timeout (600 seconds or more) |
| `GET /ng/first-or-create-in-loop` | peak_memory | unmeasured | no cold value at the size; failed tries: timeout (600 seconds or more) |
| `POST /ok/bulk` | queries | unmeasured | no cold value at the size; failed tries: 500 |
| `POST /ok/bulk` | rows_examined | unmeasured | no cold value at the size; failed tries: 500 |
| `POST /ok/bulk` | peak_memory | unmeasured | no cold value at the size; failed tries: 500 |

## bookstack: largest errors (rule B, queries and rows examined)

| # | Route | Quantity | Marked | Predicted | Measured | Relative error | Lowest | Goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `POST /books/{bookSlug}/draft/{pageId}` | rows_examined | no | 5,365,171.1 | 240,554 | 2130.34% | no | no |
| 2 | `GET /books/{bookSlug}/page/{pageSlug}` | rows_examined | yes | 3,961,435.9 | 1,570,230 | 152.28% | no | no |
| 3 | `GET /books/{bookSlug}/chapter/{chapterSlug}` | rows_examined | yes | 3,961,433.1 | 1,570,230 | 152.28% | no | no |
| 4 | `GET /books/{bookSlug}/export/zip` | rows_examined | yes | 4,201,098.5 | 1,670,196 | 151.53% | no | no |
| 5 | `GET /shelves/{slug}` | rows_examined | yes | 701.8 | 250,226 | 99.72% | no | no |
| 6 | `PUT /books/{slug}` | rows_examined | no | 5,934,428.3 | 2,990,232 | 98.46% | no | no |
| 7 | `POST /settings/maintenance/regenerate-references` | rows_examined | yes | 67,828,842.2 | 257,698,605 | 73.68% | no | no |
| 8 | `PUT /books/{bookSlug}/chapter/{chapterSlug}` | rows_examined | yes | 70,230 | 230,232 | 69.50% | no | no |
| 9 | `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | rows_examined | yes | 70,244 | 230,246 | 69.49% | no | no |
| 10 | `PUT /books/{bookSlug}/page/{pageSlug}` | rows_examined | yes | 70,255 | 230,259 | 69.49% | no | no |

## bookstack: marked routes, the point and the upper side against the cold values (rows examined)

| Route | Point (H1) | Upper side (H2) | Cold min | Cold max | Cold values (count of 4) | Point vs cold range | Upper vs cold range | Range enclosed by point and upper |
| --- | ---: | ---: | ---: | ---: | --- | --- | --- | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 1,700,513 | 5,287,558.2 | 1,040,458 | 1,700,461 | 1,400,507, 1,040,479, 1,040,458, 1,700,461 (4/4) | above | above | no |
| `DELETE /books/{bookSlug}/draft/{pageId}` | 1,700,507 | 5,287,453.5 | 1,040,454 | 1,700,455 | 1,040,454, 1,550,482, 1,700,455, 1,700,455 (4/4) | above | above | no |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | 1,700,512 | 5,287,359.8 | 1,040,459 | 1,190,461 | 1,040,459, 1,040,459, 1,190,461, 1,040,459 (4/4) | above | above | no |
| `DELETE /books/{id}` | 1,700,527 | 5,287,670.9 | 1,040,500 | 1,550,501 | 1,550,501, 1,190,503, 1,040,500, 1,190,503 (4/4) | above | above | no |
| `DELETE /shelves/{slug}` | 1,764,540.0 | 5,351,782.6 | 1,040,451 | 1,700,456 | 1,700,456, 1,700,456, 1,040,451, 1,040,451 (4/4) | above | above | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | 3,961,433.1 | 3,961,433.1 | 440,239 | 1,570,230 | 440,239, 440,239, 1,570,230, 1,090,227 (4/4) | above | above | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 70,215 | 456,334.1 | 70,215 | 70,215 | 70,215, 70,215, 70,215, 70,215 (4/4) | inside | above | yes |
| `GET /books/{bookSlug}/export/zip` | 4,201,098.5 | 4,222,683.8 | 1,670,196 | 1,670,196 | 1,670,196, 1,670,196, 1,670,196, 1,670,196 (4/4) | above | above | no |
| `GET /books/{bookSlug}/page/{pageSlug}` | 3,961,435.9 | 3,961,435.9 | 840,237 | 1,570,230 | 840,237, 1,320,240, 1,570,230, 1,090,227 (4/4) | above | above | no |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 80,218 | 575,382.8 | 80,218 | 80,218 | 80,218, 80,218, 80,218, 80,218 (4/4) | inside | above | yes |
| `GET /books/{slug}` | 7,273,953.5 | 7,273,953.5 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /books/{slug}/references` | 70,210 | 548,577.3 | 70,210 | 70,210 | 70,210, 70,210, 70,210, 70,210 (4/4) | inside | above | yes |
| `GET /shelves/{slug}` | 701.8 | 701.8 | 250,226 | 250,226 | 250,226, 250,226, 250,226, 250,226 (4/4) | below | below | no |
| `GET /shelves/{slug}/references` | 70,210 | 545,459.7 | 70,210 | 70,210 | 70,210, 70,210, 70,210, 70,210 (4/4) | inside | above | yes |
| `POST /settings/maintenance/regenerate-references` | 67,828,842.2 | 144,064,875.3 | 257,698,605 | 257,698,605 | 257,698,605, 257,698,605, 257,698,605, 257,698,605 (4/4) | below | below | no |
| `POST /settings/recycle-bin/empty` | 1,700,491 | 5,287,240.1 | 1,040,467 | 1,700,468 | 1,550,466, 1,700,468, 1,040,467, 1,040,467 (4/4) | above | above | no |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | 70,230 | 395,176.5 | 230,232 | 230,232 | 230,232, 230,232, 230,232, 230,232 (4/4) | below | above | yes |
| `PUT /books/{bookSlug}/page/{pageSlug}` | 70,255 | 415,424.0 | 230,257 | 230,259 | 230,259, 230,257, 230,257, 230,257 (4/4) | below | above | yes |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | 70,244 | 415,413.0 | 230,246 | 230,246 | 230,246, 230,246, 230,246, 230,246 (4/4) | below | above | yes |
| `PUT /shelves/{slug}` | 236,409.2 | 1,230,766.6 | 230,232 | 230,232 | 230,232, 230,232, 230,232, 230,232 (4/4) | above | above | no |


### bookstack: every try of the marked routes (which were used)

| Route | Kind | Run | State | Result | Rows examined |
| --- | --- | --- | --- | --- | ---: |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | measure | measurement run 1 | used | ok | 1,400,507 |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,040,479 |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,040,458 |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,700,461 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | measure | measurement run 1 | used | ok | 1,040,454 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 1,550,482 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 1,700,455 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 1,700,455 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | measure | measurement run 1 | used | ok | 1,040,459 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,040,459 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,190,461 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,040,459 |
| `DELETE /books/{id}` | measure | measurement run 1 | used | ok | 1,550,501 |
| `DELETE /books/{id}` | variance | variance run 1 | used | ok | 1,190,503 |
| `DELETE /books/{id}` | variance | variance run 1 | used | ok | 1,040,500 |
| `DELETE /books/{id}` | variance | variance run 1 | used | ok | 1,190,503 |
| `DELETE /shelves/{slug}` | measure | measurement run 1 | used | ok | 1,700,456 |
| `DELETE /shelves/{slug}` | variance | variance run 1 | used | ok | 1,700,456 |
| `DELETE /shelves/{slug}` | variance | variance run 1 | used | ok | 1,040,451 |
| `DELETE /shelves/{slug}` | variance | variance run 1 | used | ok | 1,040,451 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | measure | measurement run 1 | used | ok | 440,239 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 440,239 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,570,230 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,090,227 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | measure | measurement run 1 | used | ok | 70,215 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | variance | variance run 1 | used | ok | 70,215 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | variance | variance run 1 | used | ok | 70,215 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | variance | variance run 1 | used | ok | 70,215 |
| `GET /books/{bookSlug}/export/zip` | measure | measurement run 1 | used | ok | 1,670,196 |
| `GET /books/{bookSlug}/export/zip` | variance | variance run 1 | used | ok | 1,670,196 |
| `GET /books/{bookSlug}/export/zip` | variance | variance run 1 | used | ok | 1,670,196 |
| `GET /books/{bookSlug}/export/zip` | variance | variance run 1 | used | ok | 1,670,196 |
| `GET /books/{bookSlug}/page/{pageSlug}` | measure | measurement run 1 | used | ok | 840,237 |
| `GET /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,320,240 |
| `GET /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,570,230 |
| `GET /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,090,227 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | measure | measurement run 1 | used | ok | 80,218 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | variance | variance run 1 | used | ok | 80,218 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | variance | variance run 1 | used | ok | 80,218 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | variance | variance run 1 | used | ok | 80,218 |
| `GET /books/{slug}` | measure | measurement run 1 | used | 500 | n/a |
| `GET /books/{slug}` | variance | variance run 1 | used | 500 | n/a |
| `GET /books/{slug}` | variance | variance run 1 | used | 500 | n/a |
| `GET /books/{slug}` | variance | variance run 1 | used | 500 | n/a |
| `GET /books/{slug}/references` | measure | measurement run 1 | used | ok | 70,210 |
| `GET /books/{slug}/references` | variance | variance run 1 | used | ok | 70,210 |
| `GET /books/{slug}/references` | variance | variance run 1 | used | ok | 70,210 |
| `GET /books/{slug}/references` | variance | variance run 1 | used | ok | 70,210 |
| `GET /shelves/{slug}` | measure | measurement run 1 | used | ok | 250,226 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 250,226 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 250,226 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 250,226 |
| `GET /shelves/{slug}/references` | measure | measurement run 1 | used | ok | 70,210 |
| `GET /shelves/{slug}/references` | variance | variance run 1 | used | ok | 70,210 |
| `GET /shelves/{slug}/references` | variance | variance run 1 | used | ok | 70,210 |
| `GET /shelves/{slug}/references` | variance | variance run 1 | used | ok | 70,210 |
| `POST /settings/maintenance/regenerate-references` | measure | measurement run 1 | used | ok | 257,698,605 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 257,698,605 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 257,698,605 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 257,698,605 |
| `POST /settings/recycle-bin/empty` | measure | measurement run 1 | used | ok | 1,550,466 |
| `POST /settings/recycle-bin/empty` | variance | variance run 1 | used | ok | 1,700,468 |
| `POST /settings/recycle-bin/empty` | variance | variance run 1 | used | ok | 1,040,467 |
| `POST /settings/recycle-bin/empty` | variance | variance run 1 | used | ok | 1,040,467 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | measure | measurement run 1 | used | ok | 230,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 2 | used | ok | 230,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 2 | used | ok | 230,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 2 | used | ok | 230,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/page/{pageSlug}` | measure | measurement run 1 | used | ok | 230,259 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 2 | used | ok | 230,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 2 | used | ok | 230,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 2 | used | ok | 230,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | measure | measurement run 1 | used | ok | 230,246 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance | variance run 1 | used | ok | 230,246 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance | variance run 1 | used | ok | 230,246 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance | variance run 1 | used | ok | 230,246 |
| `PUT /shelves/{slug}` | measure | measurement run 1 | used | ok | 230,232 |
| `PUT /shelves/{slug}` | variance | variance run 2 | used | ok | 230,232 |
| `PUT /shelves/{slug}` | variance | variance run 2 | used | ok | 230,232 |
| `PUT /shelves/{slug}` | variance | variance run 2 | used | ok | 230,232 |
| `PUT /shelves/{slug}` | variance | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /shelves/{slug}` | variance | variance run 1 | superseded (not used) | 422 | n/a |
| `PUT /shelves/{slug}` | variance | variance run 1 | superseded (not used) | 422 | n/a |

## bookstack: failed cold tries by class

| Which tries | timeout | 422 | 500 | exception | other |
| --- | ---: | ---: | ---: | ---: | ---: |
| used (rule B drops them; a timeout on a marked route makes it not measured) | 0 | 0 | 4 | 0 | 0 |
| superseded by a re-run (not used) | 0 | 9 | 0 | 0 | 0 |

| Route | Marked | Kind | State | Class | Status | Detail |
| --- | --- | --- | --- | --- | ---: | --- |
| `GET /books/{slug}` | yes | measure | used | 500 | 500 | Illuminate\View\ViewException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders (C |
| `GET /books/{slug}` | yes | variance | used | 500 | 500 | Illuminate\View\ViewException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders (C |
| `GET /books/{slug}` | yes | variance | used | 500 | 500 | Illuminate\View\ViewException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders (C |
| `GET /books/{slug}` | yes | variance | used | 500 | 500 | Illuminate\View\ViewException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders (C |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | yes | variance | superseded | 422 | 422 | The name field is required. / fields: name |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | yes | variance | superseded | 422 | 422 | The name field is required. / fields: name |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | yes | variance | superseded | 422 | 422 | The name field is required. / fields: name |
| `PUT /books/{bookSlug}/page/{pageSlug}` | yes | variance | superseded | 422 | 422 | The name field is required. / fields: name |
| `PUT /books/{bookSlug}/page/{pageSlug}` | yes | variance | superseded | 422 | 422 | The name field is required. / fields: name |
| `PUT /books/{bookSlug}/page/{pageSlug}` | yes | variance | superseded | 422 | 422 | The name field is required. / fields: name |
| `PUT /shelves/{slug}` | yes | variance | superseded | 422 | 422 | The name field is required. / fields: name |
| `PUT /shelves/{slug}` | yes | variance | superseded | 422 | 422 | The name field is required. / fields: name |
| `PUT /shelves/{slug}` | yes | variance | superseded | 422 | 422 | The name field is required. / fields: name |

Marked routes not measured under rule B because of a timeout (600 seconds or more): none

Timeouts recorded under another class name (counted as timeout by their detail): none

## bookstack: outside a denominator

| Route | Quantity | Category | Reason |
| --- | --- | --- | --- |
| `GET /books/{slug}` | queries | unmeasured | no cold value at the size; failed tries: 500, 500, 500, 500 |
| `GET /books/{slug}` | rows_examined | unmeasured | no cold value at the size; failed tries: 500, 500, 500, 500 |
| `GET /books/{slug}` | peak_memory | unmeasured | no cold value at the size; failed tries: 500, 500, 500, 500 |

Failed tries of routes without an estimate row (outside every denominator, they do not affect the verdict):

| Route | Class | Status | Detail |
| --- | --- | ---: | --- |
| `DELETE /api/books/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `DELETE /api/chapters/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `DELETE /api/imports/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `DELETE /api/pages/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `DELETE /api/shelves/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `DELETE /api/users/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `DELETE /settings/users/{id}` | 500 (recorded as exception) | 500 | None |
| `GET /api/books/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `GET /api/books/{id}/export/zip` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `GET /api/chapters/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `GET /api/imports/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `GET /api/pages/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `GET /api/shelves/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `GET /api/users` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `GET /api/users/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `GET /import/{id}` | 500 (recorded as exception) | 500 | Illuminate\View\ViewException: Attempt to read property "name" on null (View: /app/resources/views/exports/parts/import- |
| `GET /login/service/{socialDriver}/callback` | 500 (recorded as exception) | 500 | None |
| `GET /register` | 500 (recorded as exception) | 500 | None |
| `POST /api/imports/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `POST /api/users` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `POST /books/{bookSlug}/copy` | timeout (600 seconds or more) (recorded as exception) | 500 | Illuminate\Database\QueryException: the request ran longer than 600 seconds (Connection: mysql, Host: measure-db, Port:  |
| `POST /register` | 500 (recorded as exception) | 500 | None |
| `POST /settings/users/create` | 422 (recorded as validation_422) | 422 | The name field is required. (and 3 more errors) / fields: name, email, password, password-confirm |
| `PUT /api/books/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `PUT /api/chapters/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `PUT /api/pages/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `PUT /api/shelves/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `PUT /api/users/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |

Measured but without an estimate row: `DELETE /api/books/{id}`, `DELETE /api/chapters/{id}`, `DELETE /api/imports/{id}`, `DELETE /api/pages/{id}`, `DELETE /api/shelves/{id}`, `DELETE /api/users/{id}`, `DELETE /settings/users/{id}`, `GET /api/books/{id}`, `GET /api/books/{id}/export/zip`, `GET /api/chapters/{id}`, `GET /api/imports/{id}`, `GET /api/pages/{id}`, `GET /api/shelves/{id}`, `GET /api/users`, `GET /api/users/{id}`, `GET /import/{id}`, `GET /login/service/{socialDriver}/callback`, `GET /register`, `POST /api/imports/{id}`, `POST /api/users`, `POST /books/{bookSlug}/copy`, `POST /register`, `POST /settings/users/create`, `PUT /api/books/{id}`, `PUT /api/chapters/{id}`, `PUT /api/pages/{id}`, `PUT /api/shelves/{id}`, `PUT /api/users/{id}`

