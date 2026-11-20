# Comparison at N = 240,000

Rule B decides; rule A is shown beside it. Lowest standard: queries +-5%, rows examined +-20%. Goal: queries +-2%, rows examined +-10%. Peak memory +-30% (reported only).

## fixtures

**Rule B (decides): pass**

- queries: 21 of 21 inside +-5% (enough), needs 80%
- rows_examined: 21 of 21 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| all | rows_examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| all | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| unmarked | queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| unmarked | rows_examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| unmarked | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| marked | queries | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | rows_examined | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | peak_memory | 0 | 0 | n/a | 0 | n/a | 0 | 0 |

**Rule A (shown, does not decide): pass**

- queries: 21 of 21 inside +-5% (enough), needs 80%
- rows_examined: 21 of 21 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| all | rows_examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| all | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| unmarked | queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| unmarked | rows_examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| unmarked | peak_memory | 22 | 22 | 100.00% | 22 | 100.00% | 2 | 0 |
| marked | queries | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | rows_examined | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | peak_memory | 0 | 0 | n/a | 0 | n/a | 0 | 0 |

## bookstack

**Rule B (decides): pass**

- queries: 34 of 34 inside +-5% (enough), needs 80%
- rows_examined: 29 of 34 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 34 | 34 | 100.00% | 33 | 97.06% | 1 | 1 |
| all | rows_examined | 34 | 29 | 85.29% | 24 | 70.59% | 1 | 1 |
| all | peak_memory | 34 | 22 | 64.71% | 22 | 64.71% | 1 | 1 |
| unmarked | queries | 15 | 15 | 100.00% | 15 | 100.00% | 0 | 0 |
| unmarked | rows_examined | 15 | 15 | 100.00% | 13 | 86.67% | 0 | 0 |
| unmarked | peak_memory | 15 | 14 | 93.33% | 14 | 93.33% | 0 | 0 |
| marked | queries | 19 | 19 | 100.00% | 18 | 94.74% | 1 | 1 |
| marked | rows_examined | 19 | 14 | 73.68% | 11 | 57.89% | 1 | 1 |
| marked | peak_memory | 19 | 8 | 42.11% | 8 | 42.11% | 1 | 1 |

**Rule A (shown, does not decide): fail**

- queries: 34 of 34 inside +-5% (enough), needs 80%
- rows_examined: 26 of 34 inside +-20% (not enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 34 | 34 | 100.00% | 33 | 97.06% | 1 | 1 |
| all | rows_examined | 34 | 26 | 76.47% | 23 | 67.65% | 1 | 1 |
| all | peak_memory | 34 | 22 | 64.71% | 22 | 64.71% | 1 | 1 |
| unmarked | queries | 15 | 15 | 100.00% | 15 | 100.00% | 0 | 0 |
| unmarked | rows_examined | 15 | 15 | 100.00% | 13 | 86.67% | 0 | 0 |
| unmarked | peak_memory | 15 | 14 | 93.33% | 14 | 93.33% | 0 | 0 |
| marked | queries | 19 | 19 | 100.00% | 18 | 94.74% | 1 | 1 |
| marked | rows_examined | 19 | 11 | 57.89% | 10 | 52.63% | 1 | 1 |
| marked | peak_memory | 19 | 8 | 42.11% | 8 | 42.11% | 1 | 1 |

## both apps

**Rule B (decides): pass**

- queries: 55 of 55 inside +-5% (enough), needs 80%
- rows_examined: 50 of 55 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 55 | 55 | 100.00% | 54 | 98.18% | 2 | 3 |
| all | rows_examined | 55 | 50 | 90.91% | 45 | 81.82% | 2 | 3 |
| all | peak_memory | 56 | 44 | 78.57% | 44 | 78.57% | 3 | 1 |
| unmarked | queries | 36 | 36 | 100.00% | 36 | 100.00% | 1 | 2 |
| unmarked | rows_examined | 36 | 36 | 100.00% | 34 | 94.44% | 1 | 2 |
| unmarked | peak_memory | 37 | 36 | 97.30% | 36 | 97.30% | 2 | 0 |
| marked | queries | 19 | 19 | 100.00% | 18 | 94.74% | 1 | 1 |
| marked | rows_examined | 19 | 14 | 73.68% | 11 | 57.89% | 1 | 1 |
| marked | peak_memory | 19 | 8 | 42.11% | 8 | 42.11% | 1 | 1 |

**Rule A (shown, does not decide): pass**

- queries: 55 of 55 inside +-5% (enough), needs 80%
- rows_examined: 47 of 55 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 55 | 55 | 100.00% | 54 | 98.18% | 2 | 3 |
| all | rows_examined | 55 | 47 | 85.45% | 44 | 80.00% | 2 | 3 |
| all | peak_memory | 56 | 44 | 78.57% | 44 | 78.57% | 3 | 1 |
| unmarked | queries | 36 | 36 | 100.00% | 36 | 100.00% | 1 | 2 |
| unmarked | rows_examined | 36 | 36 | 100.00% | 34 | 94.44% | 1 | 2 |
| unmarked | peak_memory | 37 | 36 | 97.30% | 36 | 97.30% | 2 | 0 |
| marked | queries | 19 | 19 | 100.00% | 18 | 94.74% | 1 | 1 |
| marked | rows_examined | 19 | 11 | 57.89% | 10 | 52.63% | 1 | 1 |
| marked | peak_memory | 19 | 8 | 42.11% | 8 | 42.11% | 1 | 1 |

## fixtures: largest errors (rule B, queries and rows examined)

| # | Route | Quantity | Marked | Predicted | Measured | Relative error | Lowest | Goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `GET /ok/chunk` | rows_examined | no | 283,084,039.4 | 288,362,402 | 1.83% | yes | yes |
| 2 | `GET /ok/lazy` | rows_examined | no | 283,084,039.4 | 288,362,402 | 1.83% | yes | yes |
| 3 | `GET /hidden/appends` | queries | no | 240,001 | 240,001 | 0.00% | yes | yes |
| 4 | `GET /hidden/appends` | rows_examined | no | 480,003 | 480,003 | 0.00% | yes | yes |
| 5 | `GET /hidden/blade` | queries | no | 240,001 | 240,001 | 0.00% | yes | yes |
| 6 | `GET /hidden/blade` | rows_examined | no | 480,003 | 480,003 | 0.00% | yes | yes |
| 7 | `GET /hidden/cache` | queries | no | 240,001 | 240,001 | 0.00% | yes | yes |
| 8 | `GET /hidden/cache` | rows_examined | no | 480,003 | 480,003 | 0.00% | yes | yes |
| 9 | `GET /hidden/conditional-binding` | queries | no | 240,001 | 240,001 | 0.00% | yes | yes |
| 10 | `GET /hidden/conditional-binding` | rows_examined | no | 480,003 | 480,003 | 0.00% | yes | yes |

## fixtures: marked routes, the point and the upper side against the cold values (rows examined)

No marked route.

## fixtures: failed cold tries by class

| Which tries | timeout | 422 | 500 | exception | other |
| --- | ---: | ---: | ---: | ---: | ---: |
| used (rule B drops them; a timeout on a marked route makes it not measured) | 1 | 0 | 0 | 0 | 1 |
| superseded by a re-run (not used) | 0 | 0 | 0 | 0 | 0 |

| Route | Marked | Kind | State | Class | Status | Detail |
| --- | --- | --- | --- | --- | ---: | --- |
| `GET /ng/first-or-create-in-loop` | no | measure | used | other:runner_failed | None | exit 139: \\ArrayStore","session_driver":"array","mail":"Illuminate\\Support\\Testing\\Fakes\\MailFake","notification":" |
| `POST /ok/bulk` | no | measure | used | timeout (600 seconds or more) | 500 | PeakSim\Runtime\Run\RequestTimeout: the request ran longer than 600 seconds |

Marked routes not measured under rule B because of a timeout (600 seconds or more): none

Timeouts recorded under another class name (counted as timeout by their detail): none

## fixtures: outside a denominator

| Route | Quantity | Category | Reason |
| --- | --- | --- | --- |
| `GET /ng/first-or-create-in-loop` | queries | unmeasured | no cold value at the size; failed tries: other:runner_failed |
| `GET /ng/first-or-create-in-loop` | rows_examined | unmeasured | no cold value at the size; failed tries: other:runner_failed |
| `GET /ng/first-or-create-in-loop` | peak_memory | unmeasured | no cold value at the size; failed tries: other:runner_failed |
| `GET /ng/nested` | queries | no_estimate | estimate incomplete (partial) |
| `GET /ng/nested` | rows_examined | no_estimate | estimate incomplete (partial) |
| `POST /ok/bulk` | queries | no_estimate | estimate incomplete (partial) |
| `POST /ok/bulk` | rows_examined | no_estimate | estimate incomplete (partial) |
| `POST /ok/bulk` | peak_memory | unmeasured | no cold value at the size; failed tries: timeout (600 seconds or more) |

## bookstack: largest errors (rule B, queries and rows examined)

| # | Route | Quantity | Marked | Predicted | Measured | Relative error | Lowest | Goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `GET /shelves/{slug}/references` | rows_examined | yes | 84,217 | 324,204 | 74.02% | no | no |
| 2 | `GET /books/{bookSlug}/page/{pageSlug}` | rows_examined | yes | 1,608,577.8 | 1,008,237 | 59.54% | no | no |
| 3 | `GET /books/{slug}/references` | rows_examined | yes | 84,217 | 168,207 | 49.93% | no | no |
| 4 | `PUT /shelves/{slug}` | rows_examined | yes | 276,268 | 504,226 | 45.21% | no | no |
| 5 | `GET /shelves/{slug}` | rows_examined | yes | 283.8 | 236 | 20.23% | no | no |
| 6 | `DELETE /books/{id}` | rows_examined | yes | 2,157,529 | 1,860,501 | 15.96% | yes | no |
| 7 | `POST /books/{bookSlug}/draft/{pageId}` | rows_examined | no | 647,650.6 | 768,544 | 15.73% | yes | no |
| 8 | `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | rows_examined | yes | 275,590 | 324,245 | 15.01% | yes | no |
| 9 | `POST /settings/maintenance/regenerate-references` | rows_examined | yes | 316,828,222.8 | 370,678,285 | 14.53% | yes | no |
| 10 | `DELETE /settings/maintenance/cleanup-images` | rows_examined | no | 27,989,600.7 | 32,280,434 | 13.29% | yes | no |

## bookstack: marked routes, the point and the upper side against the cold values (rows examined)

| Route | Point (H1) | Upper side (H2) | Cold min | Cold max | Cold values (count of 4) | Point vs cold range | Upper vs cold range | Range enclosed by point and upper |
| --- | ---: | ---: | ---: | ---: | --- | --- | --- | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 2,157,516 | 15,139,431.5 | 1,248,458 | 2,040,453 | 1,380,503, 2,040,453, 1,428,461, 1,248,458 (4/4) | above | above | no |
| `DELETE /books/{bookSlug}/draft/{pageId}` | 2,157,511 | 15,139,306.8 | 1,248,454 | 2,040,484 | 1,248,454, 2,040,484, 1,248,454, 1,248,454 (4/4) | above | above | no |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | 2,157,517 | 15,139,193.2 | 1,248,459 | 2,040,460 | 1,248,459, 1,248,459, 2,040,460, 1,248,459 (4/4) | above | above | no |
| `DELETE /books/{id}` | 2,157,529 | 15,139,564.1 | 1,248,500 | 1,860,501 | 1,428,503, 1,860,501, 1,248,500, 1,248,500 (4/4) | above | above | no |
| `DELETE /shelves/{slug}` | 2,157,509 | 15,139,663.7 | 1,248,451 | 2,040,456 | 1,248,451, 1,248,451, 1,428,454, 2,040,456 (4/4) | above | above | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | 1,608,569.5 | 1,640,726.7 | 1,008,237 | 1,584,240 | 1,584,240, 1,584,240, 1,584,240, 1,008,237 (4/4) | above | above | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 84,222 | 1,713,912.7 | 84,215 | 84,215 | 84,215, 84,215, 84,215, 84,215 (4/4) | above | above | no |
| `GET /books/{bookSlug}/export/zip` | 2,029,168.9 | 4,132,875.7 | 2,004,196 | 2,004,196 | 2,004,196, 2,004,196, 2,004,196, 2,004,196 (4/4) | above | above | no |
| `GET /books/{bookSlug}/page/{pageSlug}` | 1,608,577.8 | 1,640,241.0 | 1,008,237 | 1,008,237 | 1,008,237, 1,008,237, 1,008,237, 1,008,237 (4/4) | above | above | no |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 96,225 | 1,958,706.5 | 96,218 | 96,218 | 96,218, 96,218, 96,218, 96,218 (4/4) | above | above | no |
| `GET /books/{slug}` | 3,071,114.2 | 3,136,390.3 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /books/{slug}/references` | 84,217 | 2,043,810.2 | 84,210 | 168,207 | 84,210, 84,210, 84,210, 168,207 (4/4) | inside | above | no |
| `GET /shelves/{slug}` | 283.8 | 777.7 | 236 | 236 | 236, 236, 236, 236 (4/4) | above | above | no |
| `GET /shelves/{slug}/references` | 84,217 | 1,867,990.7 | 84,210 | 324,204 | 84,210, 84,210, 84,210, 324,204 (4/4) | inside | above | no |
| `POST /books/{bookSlug}/copy` | n/a | 0 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `POST /settings/maintenance/regenerate-references` | 316,828,222.8 | 920,795,612.7 | 370,678,285 | 370,678,285 | 370,678,285, 370,678,285, 370,678,285, 370,678,285 (4/4) | below | above | yes |
| `POST /settings/recycle-bin/empty` | 2,157,497 | 15,139,053.5 | 1,248,438 | 2,040,468 | 1,248,438, 1,860,466, 1,248,467, 2,040,468 (4/4) | above | above | no |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | 275,568 | 1,452,510.3 | 276,232 | 276,232 | 276,232, 276,232, 276,232, 276,232 (4/4) | below | above | yes |
| `PUT /books/{bookSlug}/page/{pageSlug}` | 275,593 | 1,452,535.3 | 276,257 | 276,257 | 276,257, 276,257, 276,257, 276,257 (4/4) | below | above | yes |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | 275,590 | 1,452,532.3 | 276,246 | 324,245 | 276,246, 276,246, 276,246, 324,245 (4/4) | below | above | yes |
| `PUT /shelves/{slug}` | 276,268 | 4,389,270.3 | 276,232 | 504,226 | 276,232, 276,232, 276,232, 504,226 (4/4) | inside | above | no |


### bookstack: every try of the marked routes (which were used)

| Route | Kind | Run | State | Result | Rows examined |
| --- | --- | --- | --- | --- | ---: |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | measure | measurement run 1 | used | ok | 1,380,503 |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 2,040,453 |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,428,461 |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,248,458 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | measure | measurement run 1 | used | ok | 1,248,454 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 2,040,484 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 1,248,454 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 1,248,454 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | measure | measurement run 1 | used | ok | 1,248,459 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,248,459 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 2,040,460 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,248,459 |
| `DELETE /books/{id}` | measure | measurement run 1 | used | ok | 1,428,503 |
| `DELETE /books/{id}` | variance | variance run 1 | used | ok | 1,860,501 |
| `DELETE /books/{id}` | variance | variance run 1 | used | ok | 1,248,500 |
| `DELETE /books/{id}` | variance | variance run 1 | used | ok | 1,248,500 |
| `DELETE /shelves/{slug}` | measure | measurement run 1 | used | ok | 1,248,451 |
| `DELETE /shelves/{slug}` | variance | variance run 1 | used | ok | 1,248,451 |
| `DELETE /shelves/{slug}` | variance | variance run 1 | used | ok | 1,428,454 |
| `DELETE /shelves/{slug}` | variance | variance run 1 | used | ok | 2,040,456 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | measure | measurement run 1 | used | ok | 1,584,240 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,584,240 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,584,240 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,008,237 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | measure | measurement run 1 | used | ok | 84,215 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | variance | variance run 1 | used | ok | 84,215 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | variance | variance run 1 | used | ok | 84,215 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | variance | variance run 1 | used | ok | 84,215 |
| `GET /books/{bookSlug}/export/zip` | measure | measurement run 1 | used | ok | 2,004,196 |
| `GET /books/{bookSlug}/export/zip` | variance | variance run 1 | used | ok | 2,004,196 |
| `GET /books/{bookSlug}/export/zip` | variance | variance run 1 | used | ok | 2,004,196 |
| `GET /books/{bookSlug}/export/zip` | variance | variance run 1 | used | ok | 2,004,196 |
| `GET /books/{bookSlug}/page/{pageSlug}` | measure | measurement run 1 | used | ok | 1,008,237 |
| `GET /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,008,237 |
| `GET /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,008,237 |
| `GET /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,008,237 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | measure | measurement run 1 | used | ok | 96,218 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | variance | variance run 1 | used | ok | 96,218 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | variance | variance run 1 | used | ok | 96,218 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | variance | variance run 1 | used | ok | 96,218 |
| `GET /books/{slug}` | measure | measurement run 1 | used | 500 | n/a |
| `GET /books/{slug}` | variance | variance run 1 | used | 500 | n/a |
| `GET /books/{slug}` | variance | variance run 1 | used | 500 | n/a |
| `GET /books/{slug}` | variance | variance run 1 | used | 500 | n/a |
| `GET /books/{slug}/references` | measure | measurement run 1 | used | ok | 84,210 |
| `GET /books/{slug}/references` | variance | variance run 1 | used | ok | 84,210 |
| `GET /books/{slug}/references` | variance | variance run 1 | used | ok | 84,210 |
| `GET /books/{slug}/references` | variance | variance run 1 | used | ok | 168,207 |
| `GET /shelves/{slug}` | measure | measurement run 1 | used | ok | 236 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 236 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 236 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 236 |
| `GET /shelves/{slug}/references` | measure | measurement run 1 | used | ok | 84,210 |
| `GET /shelves/{slug}/references` | variance | variance run 1 | used | ok | 84,210 |
| `GET /shelves/{slug}/references` | variance | variance run 1 | used | ok | 84,210 |
| `GET /shelves/{slug}/references` | variance | variance run 1 | used | ok | 324,204 |
| `POST /books/{bookSlug}/copy` | measure | measurement run 1 | used | timeout (600 seconds or more) | n/a |
| `POST /books/{bookSlug}/copy` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `POST /books/{bookSlug}/copy` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `POST /books/{bookSlug}/copy` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `POST /settings/maintenance/regenerate-references` | measure | measurement run 1 | used | ok | 370,678,285 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 370,678,285 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 370,678,285 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 370,678,285 |
| `POST /settings/recycle-bin/empty` | measure | measurement run 1 | used | ok | 1,248,438 |
| `POST /settings/recycle-bin/empty` | variance | variance run 1 | used | ok | 1,860,466 |
| `POST /settings/recycle-bin/empty` | variance | variance run 1 | used | ok | 1,248,467 |
| `POST /settings/recycle-bin/empty` | variance | variance run 1 | used | ok | 2,040,468 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | measure | measurement run 1 | used | ok | 276,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 276,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 276,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 276,232 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | measure | measurement run 1 | used | ok | 276,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 276,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 276,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 276,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | measure | measurement run 1 | used | ok | 276,246 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance | variance run 1 | used | ok | 276,246 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance | variance run 1 | used | ok | 276,246 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance | variance run 1 | used | ok | 324,245 |
| `PUT /shelves/{slug}` | measure | measurement run 1 | used | ok | 276,232 |
| `PUT /shelves/{slug}` | variance | variance run 1 | used | ok | 276,232 |
| `PUT /shelves/{slug}` | variance | variance run 1 | used | ok | 276,232 |
| `PUT /shelves/{slug}` | variance | variance run 1 | used | ok | 504,226 |

## bookstack: failed cold tries by class

| Which tries | timeout | 422 | 500 | exception | other |
| --- | ---: | ---: | ---: | ---: | ---: |
| used (rule B drops them; a timeout on a marked route makes it not measured) | 4 | 0 | 4 | 0 | 0 |
| superseded by a re-run (not used) | 0 | 0 | 0 | 0 | 0 |

| Route | Marked | Kind | State | Class | Status | Detail |
| --- | --- | --- | --- | --- | ---: | --- |
| `GET /books/{slug}` | yes | measure | used | 500 | 500 | Illuminate\View\ViewException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders (C |
| `GET /books/{slug}` | yes | variance | used | 500 | 500 | Illuminate\View\ViewException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders (C |
| `GET /books/{slug}` | yes | variance | used | 500 | 500 | Illuminate\View\ViewException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders (C |
| `GET /books/{slug}` | yes | variance | used | 500 | 500 | Illuminate\View\ViewException: SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders (C |
| `POST /books/{bookSlug}/copy` | yes | measure | used | timeout (600 seconds or more) | 500 | Illuminate\Database\QueryException: the request ran longer than 600 seconds (Connection: mysql, Host: measure-db, Port:  |
| `POST /books/{bookSlug}/copy` | yes | variance | used | timeout (600 seconds or more) | 500 | Illuminate\Database\QueryException: the request ran longer than 600 seconds (Connection: mysql, Host: measure-db, Port:  |
| `POST /books/{bookSlug}/copy` | yes | variance | used | timeout (600 seconds or more) | 500 | Illuminate\Database\QueryException: the request ran longer than 600 seconds (Connection: mysql, Host: measure-db, Port:  |
| `POST /books/{bookSlug}/copy` | yes | variance | used | timeout (600 seconds or more) | 500 | Illuminate\Database\QueryException: the request ran longer than 600 seconds (Connection: mysql, Host: measure-db, Port:  |

Marked routes not measured under rule B because of a timeout (600 seconds or more): `POST /books/{bookSlug}/copy`

Timeouts recorded under another class name (counted as timeout by their detail): `POST /books/{bookSlug}/copy` (class exception, status 500), `POST /books/{bookSlug}/copy` (class exception, status 500), `POST /books/{bookSlug}/copy` (class exception, status 500), `POST /books/{bookSlug}/copy` (class exception, status 500)

## bookstack: outside a denominator

| Route | Quantity | Category | Reason |
| --- | --- | --- | --- |
| `GET /books/{slug}` | queries | unmeasured | no cold value at the size; failed tries: 500, 500, 500, 500 |
| `GET /books/{slug}` | rows_examined | unmeasured | no cold value at the size; failed tries: 500, 500, 500, 500 |
| `GET /books/{slug}` | peak_memory | unmeasured | no cold value at the size; failed tries: 500, 500, 500, 500 |
| `POST /books/{bookSlug}/copy` | queries | no_estimate | estimate incomplete (partial) |
| `POST /books/{bookSlug}/copy` | rows_examined | no_estimate | estimate incomplete (partial) |
| `POST /books/{bookSlug}/copy` | peak_memory | no_estimate | peak_memory not_predicted: scale 10000: not measured |

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
| `POST /register` | 500 (recorded as exception) | 500 | None |
| `POST /settings/users/create` | 422 (recorded as validation_422) | 422 | The name field is required. (and 3 more errors) / fields: name, email, password, password-confirm |
| `PUT /api/books/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `PUT /api/chapters/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `PUT /api/pages/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `PUT /api/shelves/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |
| `PUT /api/users/{id}` | other:unauthenticated (recorded as unauthenticated) | 401 | BookStack\Exceptions\ApiAuthException: No authorization token found on the request |

Measured but without an estimate row: `DELETE /api/books/{id}`, `DELETE /api/chapters/{id}`, `DELETE /api/imports/{id}`, `DELETE /api/pages/{id}`, `DELETE /api/shelves/{id}`, `DELETE /api/users/{id}`, `DELETE /settings/users/{id}`, `GET /api/books/{id}`, `GET /api/books/{id}/export/zip`, `GET /api/chapters/{id}`, `GET /api/imports/{id}`, `GET /api/pages/{id}`, `GET /api/shelves/{id}`, `GET /api/users`, `GET /api/users/{id}`, `GET /import/{id}`, `GET /login/service/{socialDriver}/callback`, `GET /register`, `POST /api/imports/{id}`, `POST /api/users`, `POST /register`, `POST /settings/users/create`, `PUT /api/books/{id}`, `PUT /api/chapters/{id}`, `PUT /api/pages/{id}`, `PUT /api/shelves/{id}`, `PUT /api/users/{id}`

