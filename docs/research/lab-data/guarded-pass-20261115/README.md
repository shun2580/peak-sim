# The lab with all four write guards in place: one pass of the entry (10,000 rows) and a check of the book-page lock wait (30,000 rows)

Taken on 2026-11-15, 03:09 to 03:12 (JST), after the four guards of the lab's write rule were committed (`bin/check-writes`, the entry's `EntryGuard`,
`bin/check-tables`, and the GET-only entry; see `poc/lab/README.md`, "Safety conditions, carried over", 6). It is **not a measurement**: no load, no rate, nothing here is
compared with a prediction. The 50,000- and 200,000-row databases were not touched (the counts were not even read: `bin/check-tables` of those scales stays
refused, exit 17, until a prediction is committed). The limits were the ones the application really has (`max_execution_time` 30, `request_terminate_timeout` 0s,
`fastcgi_read_timeout` 120s); `bin/check-limits --require=limited` exited 0 before each part (`limits-settings.json` in each directory).

## A. One pass of the guards and the entry on the 10,000-row database (`A-entry-10000/`)

| Step | What was run | Result |
| --- | --- | --- |
| 1 | `bin/check-limits --require=limited` | exit 0 |
| 2 | `bin/check-tables before` (baseline `tables-before.tsv`), `step` (`writes-step-pre.tsv`), then after the requests `step` (`writes-step-post.tsv`) and `after` (`tables-after.tsv`) | all four exit 0. The measured database's own rows are read: 116 write counters (one row per table, kind: insert, update, delete), and the count and `CHECKSUM TABLE` of `entities`, `images`, `references` |
| 3 | `bin/check-writes --statements=<capture.json>` with the record of the capture stack that was made for these routes (sha256 prefix `69f08fcad32bdd31`) | exit 0: 8 routes, 76 statement types, every write is to `views` only (insert in `GET /shelves/{slug}`, update in `GET /books/{slug}`) |
| 4 | one GET to each of the 8 routes of the route list, one at a time (`requests.tsv`) | 7 answered 200 and `GET /oidc/callback` answered 302 (the redirect a guest gets, as before); no 503. The latch file `contaminated.flag` was absent before and after (`latch-before.txt`, `latch-after.txt`) |

Before and after the requests the count and checksum of `entities` (10,000, 4139806228), `images` (10,000, 3661766139) and `references` (10,000, 161002965) were identical.
In the write counters, the only row that moved is the one of `views` of the measured database: inserts 1 to 1, updates 1 to 3, deletes 0 to 0 (`diff writes-step-pre.tsv writes-step-post.tsv`).
Which request made which of the two updates was not looked into.

## B. Does the book page still wait for itself? (`B-lock-30000/`)

The first calibration with the limits off found that two requests for `GET /books/1` sent at the same time waited for one another, on the row of `views` with `id` 1 (see `../calibration-limits-off/README.md`,
"A check of the book-page serialization"). The entry no longer wraps a request in a transaction. This is the same check again, in the same way, on the same 30,000-row database (statistics as left by `ANALYZE`
before): single requests, and two at once, with `GET /books/1`, through the web server and php-fpm, with the load tool's one-request script (`probe_one.js`, no rate); and the lock tables
(`performance_schema.data_lock_waits`, `performance_schema.data_locks` for `views`, `sys.innodb_lock_waits`) read about every 0.3 s while the pair ran (`locks/`).

| What was sent | Duration (ms) |
| --- | --- |
| `GET /books/1` alone, first | 4,215 |
| `GET /books/1` alone, second | 2,994 |
| `GET /books/1` twice at once (pair 1) | 3,133 and 3,154 |
| `GET /books/1` twice at once (pair 2) | 3,185 and 3,174 |

Every request returned 200 with a body of 14,613,683 bytes. In both pairs the two requests took about the same time (the slower one 1.007 and 1.003 times the faster one), and both took about as long as
a request alone (the first request alone was slower, 4.2 s; the second alone was 3.0 s). In the earlier check the second of a pair took 5.8 and 5.9 s against 3.3 s.

In the polls (17 for each pair, plus 5 with nothing running), `data_lock_waits` had no row in any of them, `sys.innodb_lock_waits` had none, and `data_locks` held no lock on `views` at any poll
(`locks/*.txt`; in the earlier check one lock of mode `X,REC_NOT_GAP` on that row was granted to one transaction and waited for by the other, in 6 of 17 polls). A poll sees an instant, so a lock that lasted less
than the 0.3 s between polls could be missed; what the durations show is that no request was held up for seconds.

After the pairs, `bin/check-tables step` and `after` exited 0 (`writes-step-after.tsv`, `tables-after.tsv`): the count and checksum of the three N tables are those of the baseline
(`entities` 30,000, 2704260852; `images` 30,000, 2658365592; `references` 30,000, 1455818882), and in the write counters the only row of the measured database that changed is `views`: updates 0 to 6
(the six requests of this part). Seven tables of the database show a counter row of zeros that was not in the baseline (tables the app opened for the first time: `bookshelves_books`,
`permission_role`, `role_permissions`, `role_user`, `roles`, `settings`, `users`); a row with no insert, update or delete is not a write, as the tool's rule says. `mysql.innodb_table_stats` of the 41 tables
was identical before and after (`stats-before.tsv`, `stats-after.tsv`; no `ANALYZE`).

What this does not show:

- A different book. All 6,000 books of this database have the slug `1`, so `GET /books/{slug}` can only reach one book and one row of `views`. That a different book never waited is not seen here either.
- More than two requests at once, or any other number of rows. Two pairs, one book, one database size.
- A rate. Nothing was sent at a rate, so what this says about the wait under load (the rate at which a book page can be served) is not measured here.
- That the removal of the transaction is the only cause of the change: the requests were sent after that commit and the earlier ones before it, and nothing else was varied on purpose.
