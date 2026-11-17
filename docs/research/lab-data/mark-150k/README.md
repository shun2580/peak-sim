# Mark measurement at 150,000 rows

Host clock, 2026-11-17. No pass or fail is written here, and nothing here is compared with the prediction.

- The prediction file `poc/lab/predictions/ladder-prediction-4-mark150k.json` was committed as `e5f406f` (2026-11-17 09:08:30) before any request was sent to the 150,000-row database. `bin/prediction-gate` on that file printed `PREDICTION OK ... e5f406f8341803fe287fdf04f4636706e722fbd5 2026-11-17T09:08:30+09:00` at 09:13 (before the mark).
- The lab's limits were never changed for this mark: `poc/lab/compose/` had no difference from `HEAD` (`git diff --stat -- poc/lab/compose` printed nothing) before and after, and the lab was at its defaults when the work began.
- 09:14:04 `bin/use-db 150000` recreated the app and web containers (exit 0, PREFLIGHT OK); the app container started at 09:14:05 on the host clock. The app container's memory limit (`HostConfig.Memory`) is 6442450944 before and after the mark, `OOMKilled` false, restart count 0.
- `limits-before-mark/` (read 09:14:19) and `limits-after-mark/` (read 09:20:49), each `bin/check-limits --require=limited`, exit 0, read through php-fpm and nginx: `max_execution_time` 30, `memory_limit` 512M, `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 120s. Nothing was changed between the two readings, so there was nothing to put back.
- 09:14:27 a first call of `request_probe.py run` stopped at once, before any request, because `targets-150000.json` did not exist (`FileNotFoundError`; the limits check had passed; no request was sent). `bin/targets --scale=150000` made it at 09:14:46 (`targets-150000.output.txt`: 8 of 8 routes filled, no request sent), using the static analysis that is in the lab's out directory (the file the 300,000-row targets were made from; its sha256 is unchanged by this).
- The mark: `request_probe.py run --scale=150000 --part=http --reps=1 --require-limits=limited` ran from **09:17:22 to 09:20:28** (`mark-times.txt`, exit 0; this is the first request sent to the 150,000-row database). Per route one warm-up (rep 0) and one request (rep 1), one at a time, no load, k6 timeout 180 s (the default, as in the earlier marks). `mark-150000/` holds the record; `request-probe-output.txt` is the tool's own output. The count of `entities`, `images` and `references` at 09:17:23 is 150,000 each (`table-counts.json`).
- `stats-mark-150000.tsv`: `innodb_table_stats` (last_update, n_rows) of the 150,000-row database read before (09:17:22) and after (09:20:28) the mark: 41 tables, no row differs between the two readings; no `ANALYZE` was run.
- The app container's log and the web container's log were copied at 09:20:3x, before any container was recreated or the stack was taken down (`app-log-after-mark.txt`, `web-log-after-mark.txt`). The web container's log is empty (0 bytes).

## Status of each request

PHP CPU time is the tool's `php_cpu_usec` (the app container's CPU time over the request, with the idle rate taken off).

| route | rep 0 (warm-up) | rep 1: status, time, PHP CPU |
|---|---|---|
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 200, 681 ms | 200, 36 ms, 0.036 s |
| `GET /books/{bookSlug}/export/zip` | 500, 53388 ms | 500, 52850 ms, 30.458 s |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 200, 50 ms | 200, 41 ms, 0.042 s |
| `GET /books/{slug}` | 500, 21929 ms | 500, 18984 ms, 17.472 s |
| `GET /books/{slug}/references` | 200, 25 ms | 200, 26 ms, 0.026 s |
| `GET /images/edit/{id}` | 200, 85 ms | 200, 21 ms, 0.022 s |
| `GET /oidc/callback` | 302, 37 ms | 302, 12 ms, 0.011 s |
| `GET /shelves/{slug}` | 200, 125 ms | 200, 48 ms, 0.037 s |

Rep 0 PHP CPU of the two 500 routes: ZIP 30.732 s, book page 20.327 s.

## Which limit ended each 500

`app-log-after-mark.txt` is the app container's log from its start (09:14:05) to the end of the mark. The log is in UTC (9 hours behind the host clock). It holds four error entries, the same four moments as the four 500 answers:

| host time | route and rep | what the log says |
|---|---|---|
| 09:18:34 | ZIP export, rep 0 | `PHP Fatal error:  Maximum execution time of 30 seconds exceeded` (`Illuminate/Database/Eloquent/Builder.php` line 1782) |
| 09:19:30 | ZIP export, rep 1 | `PHP Fatal error:  Maximum execution time of 30 seconds exceeded` (`Illuminate/Database/Connection.php` line 442) |
| 09:19:56 | book page `GET /books/{slug}`, rep 0 | `SQLSTATE[HY000]: General error: 1390 Prepared statement contains too many placeholders` (the SQL: `select * from activities where ((loggable_type = book and loggable_id in (1)) or ...`) |
| 09:20:18 | book page `GET /books/{slug}`, rep 1 | the same error 1390 |

No line of the log says `Allowed memory size ... exhausted` (0 lines), and the app container was not stopped for memory (`OOMKilled` false). The nginx 120 s limit did not end a request (the longest answer was 53.4 s).

## Files

- `mark-150000/` (`context.json`, `http.jsonl`, `table-counts.json`), `request-probe-output.txt`, `mark-times.txt`, `targets-150000.output.txt`, `stats-mark-150000.tsv`, `app-log-after-mark.txt`, `web-log-after-mark.txt`, `limits-before-mark/`, `limits-after-mark/` (`settings-*.txt` are the raw outputs of `nginx -T`, `php-fpm -tt` and the PHP probe; `settings.json` is the reading).

sha256 of the three files of `mark-150000/`: `context.json` 03deaf7e40b3eed7c818c6fda5d9dcc7046c5194e804b9f031dce711b44aa207, `http.jsonl` 8d988e79862fa0836bb8406e66956673ff3e1a72381d5a34223a3724de49ee01, `table-counts.json` 18a8ca33c5a1f7066f70f359e414d934bad03fe6758ec160d4c13fe3f407dc3a.
