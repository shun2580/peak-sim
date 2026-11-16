# Comparison at N = 300,000

Rule B decides; rule A is shown beside it. Lowest standard: queries +-5%, rows examined +-20%. Goal: queries +-2%, rows examined +-10%. Peak memory +-30% (reported only).

## fixtures

**Rule B (decides): pass**

- queries: 21 of 21 inside +-5% (enough), needs 80%
- rows_examined: 21 of 21 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| all | rows_examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| all | peak_memory | 21 | 21 | 100.00% | 21 | 100.00% | 3 | 0 |
| unmarked | queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| unmarked | rows_examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| unmarked | peak_memory | 21 | 21 | 100.00% | 21 | 100.00% | 3 | 0 |
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
| all | peak_memory | 21 | 21 | 100.00% | 21 | 100.00% | 3 | 0 |
| unmarked | queries | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| unmarked | rows_examined | 21 | 21 | 100.00% | 21 | 100.00% | 1 | 2 |
| unmarked | peak_memory | 21 | 21 | 100.00% | 21 | 100.00% | 3 | 0 |
| marked | queries | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | rows_examined | 0 | 0 | n/a | 0 | n/a | 0 | 0 |
| marked | peak_memory | 0 | 0 | n/a | 0 | n/a | 0 | 0 |

## bookstack

**Rule B (decides): fail**

- queries: 32 of 33 inside +-5% (enough), needs 80%
- rows_examined: 24 of 33 inside +-20% (not enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 33 | 32 | 96.97% | 32 | 96.97% | 2 | 1 |
| all | rows_examined | 33 | 24 | 72.73% | 15 | 45.45% | 2 | 1 |
| all | peak_memory | 33 | 22 | 66.67% | 22 | 66.67% | 2 | 1 |
| unmarked | queries | 13 | 13 | 100.00% | 13 | 100.00% | 1 | 0 |
| unmarked | rows_examined | 13 | 13 | 100.00% | 11 | 84.62% | 1 | 0 |
| unmarked | peak_memory | 13 | 12 | 92.31% | 12 | 92.31% | 1 | 0 |
| marked | queries | 20 | 19 | 95.00% | 19 | 95.00% | 1 | 1 |
| marked | rows_examined | 20 | 11 | 55.00% | 4 | 20.00% | 1 | 1 |
| marked | peak_memory | 20 | 10 | 50.00% | 10 | 50.00% | 1 | 1 |

**Rule A (shown, does not decide): fail**

- queries: 32 of 33 inside +-5% (enough), needs 80%
- rows_examined: 21 of 33 inside +-20% (not enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 33 | 32 | 96.97% | 31 | 93.94% | 2 | 1 |
| all | rows_examined | 33 | 21 | 63.64% | 17 | 51.52% | 2 | 1 |
| all | peak_memory | 33 | 21 | 63.64% | 21 | 63.64% | 2 | 1 |
| unmarked | queries | 13 | 13 | 100.00% | 13 | 100.00% | 1 | 0 |
| unmarked | rows_examined | 13 | 13 | 100.00% | 11 | 84.62% | 1 | 0 |
| unmarked | peak_memory | 13 | 12 | 92.31% | 12 | 92.31% | 1 | 0 |
| marked | queries | 20 | 19 | 95.00% | 18 | 90.00% | 1 | 1 |
| marked | rows_examined | 20 | 8 | 40.00% | 6 | 30.00% | 1 | 1 |
| marked | peak_memory | 20 | 9 | 45.00% | 9 | 45.00% | 1 | 1 |

## both apps

**Rule B (decides): pass**

- queries: 53 of 54 inside +-5% (enough), needs 80%
- rows_examined: 45 of 54 inside +-20% (enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 54 | 53 | 98.15% | 53 | 98.15% | 3 | 3 |
| all | rows_examined | 54 | 45 | 83.33% | 36 | 66.67% | 3 | 3 |
| all | peak_memory | 54 | 43 | 79.63% | 43 | 79.63% | 5 | 1 |
| unmarked | queries | 34 | 34 | 100.00% | 34 | 100.00% | 2 | 2 |
| unmarked | rows_examined | 34 | 34 | 100.00% | 32 | 94.12% | 2 | 2 |
| unmarked | peak_memory | 34 | 33 | 97.06% | 33 | 97.06% | 4 | 0 |
| marked | queries | 20 | 19 | 95.00% | 19 | 95.00% | 1 | 1 |
| marked | rows_examined | 20 | 11 | 55.00% | 4 | 20.00% | 1 | 1 |
| marked | peak_memory | 20 | 10 | 50.00% | 10 | 50.00% | 1 | 1 |

**Rule A (shown, does not decide): fail**

- queries: 53 of 54 inside +-5% (enough), needs 80%
- rows_examined: 42 of 54 inside +-20% (not enough), needs 80%

| Group | Quantity | Denominator | Within lowest standard | Share | Within goal | Share | Not measured | No estimate |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| all | queries | 54 | 53 | 98.15% | 52 | 96.30% | 3 | 3 |
| all | rows_examined | 54 | 42 | 77.78% | 38 | 70.37% | 3 | 3 |
| all | peak_memory | 54 | 42 | 77.78% | 42 | 77.78% | 5 | 1 |
| unmarked | queries | 34 | 34 | 100.00% | 34 | 100.00% | 2 | 2 |
| unmarked | rows_examined | 34 | 34 | 100.00% | 32 | 94.12% | 2 | 2 |
| unmarked | peak_memory | 34 | 33 | 97.06% | 33 | 97.06% | 4 | 0 |
| marked | queries | 20 | 19 | 95.00% | 18 | 90.00% | 1 | 1 |
| marked | rows_examined | 20 | 8 | 40.00% | 6 | 30.00% | 1 | 1 |
| marked | peak_memory | 20 | 9 | 45.00% | 9 | 45.00% | 1 | 1 |

## fixtures: largest errors (rule B, queries and rows examined)

| # | Route | Quantity | Marked | Predicted | Measured | Relative error | Lowest | Goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `GET /ok/chunk` | rows_examined | no | 441,256,683.7 | 450,453,002 | 2.04% | yes | yes |
| 2 | `GET /ok/lazy` | rows_examined | no | 441,256,683.7 | 450,453,002 | 2.04% | yes | yes |
| 3 | `GET /hidden/appends` | queries | no | 300,001 | 300,001 | 0.00% | yes | yes |
| 4 | `GET /hidden/appends` | rows_examined | no | 600,003 | 600,003 | 0.00% | yes | yes |
| 5 | `GET /hidden/blade` | queries | no | 300,001 | 300,001 | 0.00% | yes | yes |
| 6 | `GET /hidden/blade` | rows_examined | no | 600,003 | 600,003 | 0.00% | yes | yes |
| 7 | `GET /hidden/cache` | queries | no | 300,001 | 300,001 | 0.00% | yes | yes |
| 8 | `GET /hidden/cache` | rows_examined | no | 600,003 | 600,003 | 0.00% | yes | yes |
| 9 | `GET /hidden/conditional-binding` | queries | no | 300,001 | 300,001 | 0.00% | yes | yes |
| 10 | `GET /hidden/conditional-binding` | rows_examined | no | 600,003 | 600,003 | 0.00% | yes | yes |

## fixtures: marked routes, the point and the upper side against the cold values (rows examined)

No marked route.

## fixtures: failed cold tries by class

| Which tries | timeout | 422 | 500 | exception | other |
| --- | ---: | ---: | ---: | ---: | ---: |
| used (rule B drops them; a timeout on a marked route makes it not measured) | 2 | 0 | 0 | 0 | 1 |
| superseded by a re-run (not used) | 0 | 0 | 0 | 0 | 0 |

| Route | Marked | Kind | State | Class | Status | Detail |
| --- | --- | --- | --- | --- | ---: | --- |
| `GET /ng/first-or-create-in-loop` | no | measure | used | other:runner_failed | None | exit 139: \\ArrayStore","session_driver":"array","mail":"Illuminate\\Support\\Testing\\Fakes\\MailFake","notification":" |
| `GET /ng/nested` | no | measure | used | timeout (600 seconds or more) | 500 | Illuminate\Database\QueryException: the request ran longer than 600 seconds (Connection: mysql, Host: measure-db, Port:  |
| `POST /ok/bulk` | no | measure | used | timeout (600 seconds or more) | 500 | PeakSim\Runtime\Run\RequestTimeout: the request ran longer than 600 seconds |

Marked routes not measured under rule B because of a timeout (600 seconds or more): none

Timeouts recorded under another class name (counted as timeout by their detail): `GET /ng/nested` (class exception, status 500)

## fixtures: outside a denominator

| Route | Quantity | Category | Reason |
| --- | --- | --- | --- |
| `GET /ng/first-or-create-in-loop` | queries | unmeasured | no cold value at the size; failed tries: other:runner_failed |
| `GET /ng/first-or-create-in-loop` | rows_examined | unmeasured | no cold value at the size; failed tries: other:runner_failed |
| `GET /ng/first-or-create-in-loop` | peak_memory | unmeasured | no cold value at the size; failed tries: other:runner_failed |
| `GET /ng/nested` | queries | no_estimate | estimate incomplete (partial) |
| `GET /ng/nested` | rows_examined | no_estimate | estimate incomplete (partial) |
| `GET /ng/nested` | peak_memory | unmeasured | no cold value at the size; failed tries: timeout (600 seconds or more) |
| `POST /ok/bulk` | queries | no_estimate | estimate incomplete (partial) |
| `POST /ok/bulk` | rows_examined | no_estimate | estimate incomplete (partial) |
| `POST /ok/bulk` | peak_memory | unmeasured | no cold value at the size; failed tries: timeout (600 seconds or more) |

## bookstack: largest errors (rule B, queries and rows examined)

| # | Route | Quantity | Marked | Predicted | Measured | Relative error | Lowest | Goal |
| ---: | --- | --- | --- | ---: | ---: | ---: | --- | --- |
| 1 | `GET /shelves/{slug}` | rows_examined | yes | 289.0 | 375,226 | 99.92% | no | no |
| 2 | `PUT /books/{bookSlug}/chapter/{chapterSlug}` | rows_examined | yes | 75,763.8 | 345,240 | 78.05% | no | no |
| 3 | `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | rows_examined | yes | 75,787.2 | 345,262 | 78.05% | no | no |
| 4 | `PUT /books/{bookSlug}/page/{pageSlug}` | rows_examined | yes | 75,790.2 | 345,265 | 78.05% | no | no |
| 5 | `GET /books/{slug}/references` | rows_examined | yes | 40,810.7 | 105,210 | 61.21% | no | no |
| 6 | `GET /shelves/{slug}/references` | rows_examined | yes | 40,813.7 | 105,210 | 61.21% | no | no |
| 7 | `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | rows_examined | yes | 40,817.2 | 105,215 | 61.21% | no | no |
| 8 | `GET /books/{bookSlug}/page/{pageSlug}/references` | rows_examined | yes | 49,560.5 | 120,219 | 58.77% | no | no |
| 9 | `GET /books/{bookSlug}/page/{pageSlug}` | rows_examined | yes | 2,011,437.3 | 1,635,227 | 23.01% | no | no |
| 10 | `DELETE /books/{bookSlug}/page/{pageSlug}` | rows_examined | yes | 2,121,113.4 | 2,550,460 | 16.83% | yes | no |

## bookstack: marked routes, the point and the upper side against the cold values (rows examined)

| Route | Point (H1) | Upper side (H2) | Cold min | Cold max | Cold values (count of 4) | Point vs cold range | Upper vs cold range | Range enclosed by point and upper |
| --- | ---: | ---: | ---: | ---: | --- | --- | --- | --- |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | 2,121,131.8 | 18,933,014.7 | 1,560,450 | 2,550,461 | 2,550,453, 1,560,450, 1,560,458, 2,550,461 (4/4) | inside | above | no |
| `DELETE /books/{bookSlug}/draft/{pageId}` | 2,121,117.1 | 18,932,860.0 | 1,560,454 | 2,550,455 | 1,560,454, 1,560,454, 2,550,455, 1,560,454 (4/4) | inside | above | no |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | 2,121,113.4 | 18,932,716.4 | 1,560,459 | 2,550,460 | 1,560,459, 1,560,459, 2,550,460, 1,725,512 (4/4) | inside | above | no |
| `DELETE /books/{id}` | 2,121,154.5 | 18,933,177.3 | 1,560,500 | 2,550,475 | 1,560,500, 1,785,503, 1,560,500, 2,550,475 (4/4) | inside | above | no |
| `DELETE /shelves/{slug}` | 2,121,144.3 | 18,933,306.9 | 2,100,450 | 2,550,456 | 2,550,456, 2,100,450, 2,550,456, 2,550,456 (4/4) | inside | above | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | 2,011,475.8 | 2,059,603.2 | 1,980,240 | 2,355,230 | 1,980,240, 1,980,240, 2,355,230, 2,355,230 (4/4) | inside | inside | no |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 40,817.2 | 2,121,369.4 | 105,215 | 105,215 | 105,215, 105,215, 105,215, 105,215 (4/4) | below | above | yes |
| `GET /books/{bookSlug}/draft/{pageId}` | 211 | 211 | 211 | 211 | 211, 211, 211, 211 (4/4) | inside | inside | yes |
| `GET /books/{bookSlug}/export/zip` | 2,536,922.4 | 5,371,397.7 | 2,505,196 | 2,505,196 | 2,505,196, 2,505,196, 2,505,196, 2,505,196 (4/4) | above | above | no |
| `GET /books/{bookSlug}/page/{pageSlug}` | 2,011,437.3 | 2,057,605.1 | 1,260,237 | 1,635,227 | 1,260,237, 1,260,237, 1,635,227, 1,635,227 (4/4) | above | above | no |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 49,560.5 | 2,424,365.7 | 120,219 | 120,219 | 120,219, 120,219, 120,219, 120,219 (4/4) | below | above | yes |
| `GET /books/{slug}` | 3,840,199.8 | 3,935,034.4 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `GET /books/{slug}/references` | 40,810.7 | 2,550,721.6 | 105,210 | 105,210 | 105,210, 105,210, 105,210, 105,210 (4/4) | below | above | yes |
| `GET /shelves/{slug}` | 289.0 | 1,268.9 | 375,226 | 375,226 | 375,226, 375,226, 375,226, 375,226 (4/4) | below | below | no |
| `GET /shelves/{slug}/references` | 40,813.7 | 2,316,459.7 | 105,210 | 105,210 | 105,210, 105,210, 105,210, 105,210 (4/4) | below | above | yes |
| `POST /books/{bookSlug}/copy` | n/a | 0 | n/a | n/a |  (0/4) | n/a | n/a | n/a |
| `POST /settings/maintenance/regenerate-references` | 494,672,778.3 | 1,494,011,475.6 | 544,067,903 | 571,035,404 | 544,458,853, 550,761,653, 544,067,903, 571,035,404 (4/4) | below | above | yes |
| `POST /settings/recycle-bin/empty` | 2,121,083.7 | 18,932,546.8 | 1,560,467 | 2,325,466 | 2,325,466, 1,560,467, 1,785,469, 1,785,469 (4/4) | inside | above | no |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | 75,763.8 | 1,806,389.1 | 345,232 | 345,240 | 345,232, 345,232, 345,240, 345,240 (4/4) | below | above | yes |
| `PUT /books/{bookSlug}/page/{pageSlug}` | 75,790.2 | 1,806,414.1 | 345,257 | 345,265 | 345,257, 345,265, 345,265, 345,265 (4/4) | below | above | yes |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | 75,787.2 | 1,806,411.1 | 345,246 | 345,262 | 345,246, 345,262, 345,262, 345,262 (4/4) | below | above | yes |
| `PUT /shelves/{slug}` | 315,797.3 | 5,477,597.3 | 345,232 | 345,240 | 345,232, 345,240, 345,240, 345,240 (4/4) | below | above | yes |


### bookstack: every try of the marked routes (which were used)

| Route | Kind | Run | State | Result | Rows examined |
| --- | --- | --- | --- | --- | ---: |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | measure | measurement run 1 | used | ok | 2,550,453 |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,560,450 |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,560,458 |
| `DELETE /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 2,550,461 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | measure | measurement run 1 | used | ok | 1,560,454 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 1,560,454 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 2,550,455 |
| `DELETE /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 1,560,454 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | measure | measurement run 1 | used | ok | 1,560,459 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,560,459 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 2,550,460 |
| `DELETE /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,725,512 |
| `DELETE /books/{id}` | measure | measurement run 1 | used | ok | 1,560,500 |
| `DELETE /books/{id}` | variance | variance run 1 | used | ok | 1,785,503 |
| `DELETE /books/{id}` | variance | variance run 1 | used | ok | 1,560,500 |
| `DELETE /books/{id}` | variance | variance run 1 | used | ok | 2,550,475 |
| `DELETE /shelves/{slug}` | measure | measurement run 1 | used | ok | 2,550,456 |
| `DELETE /shelves/{slug}` | variance | variance run 1 | used | ok | 2,100,450 |
| `DELETE /shelves/{slug}` | variance | variance run 1 | used | ok | 2,550,456 |
| `DELETE /shelves/{slug}` | variance | variance run 1 | used | ok | 2,550,456 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | measure | measurement run 1 | used | ok | 1,980,240 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 1,980,240 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 2,355,230 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 2,355,230 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | measure | measurement run 1 | used | ok | 105,215 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | variance | variance run 1 | used | ok | 105,215 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | variance | variance run 1 | used | ok | 105,215 |
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | variance | variance run 1 | used | ok | 105,215 |
| `GET /books/{bookSlug}/draft/{pageId}` | measure | measurement run 1 | used | ok | 211 |
| `GET /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 211 |
| `GET /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 211 |
| `GET /books/{bookSlug}/draft/{pageId}` | variance | variance run 1 | used | ok | 211 |
| `GET /books/{bookSlug}/export/zip` | measure | measurement run 1 | used | ok | 2,505,196 |
| `GET /books/{bookSlug}/export/zip` | variance | variance run 1 | used | ok | 2,505,196 |
| `GET /books/{bookSlug}/export/zip` | variance | variance run 1 | used | ok | 2,505,196 |
| `GET /books/{bookSlug}/export/zip` | variance | variance run 1 | used | ok | 2,505,196 |
| `GET /books/{bookSlug}/page/{pageSlug}` | measure | measurement run 1 | used | ok | 1,260,237 |
| `GET /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,260,237 |
| `GET /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,635,227 |
| `GET /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 1,635,227 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | measure | measurement run 1 | used | ok | 120,219 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | variance | variance run 1 | used | ok | 120,219 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | variance | variance run 1 | used | ok | 120,219 |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | variance | variance run 1 | used | ok | 120,219 |
| `GET /books/{slug}` | measure | measurement run 1 | used | 500 | n/a |
| `GET /books/{slug}` | variance | variance run 1 | used | 500 | n/a |
| `GET /books/{slug}` | variance | variance run 1 | used | 500 | n/a |
| `GET /books/{slug}` | variance | variance run 1 | used | 500 | n/a |
| `GET /books/{slug}/references` | measure | measurement run 1 | used | ok | 105,210 |
| `GET /books/{slug}/references` | variance | variance run 1 | used | ok | 105,210 |
| `GET /books/{slug}/references` | variance | variance run 1 | used | ok | 105,210 |
| `GET /books/{slug}/references` | variance | variance run 1 | used | ok | 105,210 |
| `GET /shelves/{slug}` | measure | measurement run 1 | used | ok | 375,226 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 375,226 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 375,226 |
| `GET /shelves/{slug}` | variance | variance run 1 | used | ok | 375,226 |
| `GET /shelves/{slug}/references` | measure | measurement run 1 | used | ok | 105,210 |
| `GET /shelves/{slug}/references` | variance | variance run 1 | used | ok | 105,210 |
| `GET /shelves/{slug}/references` | variance | variance run 1 | used | ok | 105,210 |
| `GET /shelves/{slug}/references` | variance | variance run 1 | used | ok | 105,210 |
| `POST /books/{bookSlug}/copy` | measure | measurement run 1 | used | timeout (600 seconds or more) | n/a |
| `POST /books/{bookSlug}/copy` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `POST /books/{bookSlug}/copy` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `POST /books/{bookSlug}/copy` | variance | variance run 1 | used | timeout (600 seconds or more) | n/a |
| `POST /settings/maintenance/regenerate-references` | measure | measurement run 1 | used | ok | 544,458,853 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 550,761,653 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 544,067,903 |
| `POST /settings/maintenance/regenerate-references` | variance | variance run 1 | used | ok | 571,035,404 |
| `POST /settings/recycle-bin/empty` | measure | measurement run 1 | used | ok | 2,325,466 |
| `POST /settings/recycle-bin/empty` | variance | variance run 1 | used | ok | 1,560,467 |
| `POST /settings/recycle-bin/empty` | variance | variance run 1 | used | ok | 1,785,469 |
| `POST /settings/recycle-bin/empty` | variance | variance run 1 | used | ok | 1,785,469 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | measure | measurement run 1 | used | ok | 345,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 345,232 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 345,240 |
| `PUT /books/{bookSlug}/chapter/{chapterSlug}` | variance | variance run 1 | used | ok | 345,240 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | measure | measurement run 1 | used | ok | 345,257 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 345,265 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 345,265 |
| `PUT /books/{bookSlug}/page/{pageSlug}` | variance | variance run 1 | used | ok | 345,265 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | measure | measurement run 1 | used | ok | 345,246 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance | variance run 1 | used | ok | 345,262 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance | variance run 1 | used | ok | 345,262 |
| `PUT /books/{bookSlug}/page/{pageSlug}/revisions/{revId}/restore` | variance | variance run 1 | used | ok | 345,262 |
| `PUT /shelves/{slug}` | measure | measurement run 1 | used | ok | 345,232 |
| `PUT /shelves/{slug}` | variance | variance run 1 | used | ok | 345,240 |
| `PUT /shelves/{slug}` | variance | variance run 1 | used | ok | 345,240 |
| `PUT /shelves/{slug}` | variance | variance run 1 | used | ok | 345,240 |

## bookstack: failed cold tries by class

| Which tries | timeout | 422 | 500 | exception | other |
| --- | ---: | ---: | ---: | ---: | ---: |
| used (rule B drops them; a timeout on a marked route makes it not measured) | 4 | 1 | 4 | 0 | 0 |
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
| `PUT /books/{slug}` | no | measure | used | 422 | 422 | The name field is required. / fields: name |

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
| `PUT /books/{slug}` | queries | unmeasured | no cold value at the size; failed tries: 422 |
| `PUT /books/{slug}` | rows_examined | unmeasured | no cold value at the size; failed tries: 422 |
| `PUT /books/{slug}` | peak_memory | unmeasured | no cold value at the size; failed tries: 422 |

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

