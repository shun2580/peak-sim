# The lab entry, passed once for real (10,000 rows)

One pass of each of the 8 routes and one POST through the real lab stack (php-fpm behind nginx, MySQL) on the database of
10,000 rows, after the entry stopped wrapping a request in a transaction (`poc/lab/app/entry.php`, `EntryGuard`). This is a
check that the guards let the allowed requests through and change nothing but the view counter. It is **not a measurement**: no
load, one request per route, nothing is timed or compared.

Taken on 2026-11-15, 02:10 (JST). The other databases (50,000 and 200,000 rows) were not touched.

| Step | Result |
| --- | --- |
| Limits read before (`bin/check-limits --require=limited`) | exit 0: PHP `max_execution_time` 30, pool `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 120s |
| App pointed at | scale 10000 (read from the container's environment) |
| Latch file (`contaminated.flag`) before / after | absent / absent |
| 8 routes, one GET each (`routes.tsv`, `requests.tsv`) | 7 answered 200; `GET /oidc/callback` answered 302 (the redirect to the login page, as expected for a guest); no 503 |
| POST once to `/books/1` (`post.txt`) | 405 Method Not Allowed |
| Row counts of every table of the database, before and after (`counts-*.tsv`, `counts.diff`) | the only difference is `views`: 10000 to 10001 (+1). Every other table is unchanged |

The +1 in `views` is what the two statements the app writes would give: `GET /shelves/{slug}` inserts one row, and
`GET /books/{slug}` updates an existing row (no new row). Which statement made which change was not observed here.

What this does not show: the guard's refusal path (a statement that must latch the lab) was **not** tried on the real lab, because
a latched lab can only be cleared by rebuilding it. That path is covered by the unit tests in `poc/runtime/tests/Unit/LabEntry/`.
