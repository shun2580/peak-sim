# The ZIP export route with and without PHP's execution-time limit (100,000 rows)

A diagnosis of the lab backtest's miss. Nothing here is a prediction or a score, and nothing in the backtest's own records was touched.

At 100,000 rows the route `GET /books/{bookSlug}/export/zip` is cut by PHP's execution-time limit and answers HTTP 500. This directory sets
three cold requests with the limit as it is (the one-request record `../per-request/100000/`) beside three cold requests with the limit
taken off, on the same database, one request at a time, read the same way.

## What was changed, and put back

The lab's PHP settings come from `poc/lab/compose/php/zz-lab.ini`, which php-fpm reads (the file is mounted into the app container; the
pool's own file `zz-lab-fpm.conf` was not touched). The execution-time limit is not named in it, so PHP's default of 30 seconds holds.

1. Before: the settings were read (below), and a copy of `zz-lab.ini` was kept outside the repository with its SHA-256.
2. Changed: two lines were appended to `zz-lab.ini` (a comment and `max_execution_time=0`). `poc/lab/bin/use-db 100000` recreated the app
   and web containers (the lab's own way to restart them, with `docker compose`; no `kill`-type command was used). Its closed-network check passed.
3. Measured: `request_probe.py run --scale=100000 --part=http --routes=2 --reps=3` (record: `unlimited-100000/`).
4. Put back: the kept copy was written over `zz-lab.ini`, and `use-db 100000` recreated the containers again.
5. Shown (below): the same readings after. `git status` and `git diff` of `poc/lab/compose/` are empty. The change was never committed.

### The settings at the three times (`settings-1-before.txt`, `settings-2-unlimited.txt`, `settings-3-after.txt`)

Read through php-fpm (a FastCGI request to `ini-probe.php`, so the value is the one the app's own requests see; the command line's `php -i`
shows the command line's value, not php-fpm's), and by php-fpm parsing its own configuration (`php-fpm -tt`):

| | before | unlimited | after |
| --- | --- | --- | --- |
| `max_execution_time` seen by a request through php-fpm | 30 | 0 | 30 |
| `zz-lab.ini` SHA-256 (first 8 characters) | f495eab5 | 28396641 | f495eab5 |
| a `max_execution_time` line in the file inside the container | none | `=0` | none |
| `git status` / `git diff` of `poc/lab/compose/php/` | empty | 1 file, 3 lines added | empty |
| `request_terminate_timeout` (php-fpm pool) | 0s | 0s | 0s |
| `request_slowlog_timeout` (php-fpm pool) | 0s | 0s | 0s |
| nginx `fastcgi_read_timeout` | 120s | 120s | 120s |

Every other setting that could end a request is unchanged. `fastcgi_read_timeout` is 120 s and no request here took that long, so the web
server did not cut any.

## Does the limit count waiting or CPU time?

Through php-fpm, with the limit set to 2 seconds inside the request (`ini-probe.php`, real output, the same at all three times):

- `mode=sleep` (waits 4 seconds, uses no CPU): `{"ended_by_the_limit":false,"wall_s":4,"cpu_s":0}`. The wait was not counted.
- `mode=spin` (uses CPU for 6 seconds): `{"ended_by_the_limit":true,"wall_s":2,"cpu_s":2}`. Cut at 2 seconds of CPU.

So the limit counts the process's CPU time and not the time it waits (for the database, for example). The ZIP request agrees: with the
limit on, it answered after 52.9 to 53.8 seconds while php-fpm had used 30.2 to 30.7 seconds of CPU.

## The two records, side by side

Three cold requests each (the warm-up request is kept in the record and not used). Medians; every reading is in `compare.json`. The
database's CPU is its container's `usage_usec` over the window with the empty window and the idle drift taken off; the window closes
when php-fpm has finished (the app container's CPU stops growing), not when the client got its answer. `handler reads` and `statements`
are the server's own counters (`Handler_read_*`, `Questions`) over the window.

| measure | limit on (cut) | limit off | off ÷ on | on as a share of off |
| --- | --- | --- | --- | --- |
| HTTP status | 500, 500, 500 | 200, 200, 200 | | |
| elapsed until the answer (s) | 53.1 | 71.1 | 1.34 | 0.75 |
| php-fpm's CPU time (s) | 30.4 | 40.9 | 1.34 | 0.74 |
| database CPU time (s) | 25.2 | 33.4 | 1.33 | 0.75 |
| rows read by the server (`Handler_read_*`) | 799,197 | 835,207 | 1.05 | 0.96 |
| statements counted by the server (`Questions`) | 96,024 | 120,036 | 1.25 | 0.80 |

The request without the limit also reads the rows and counts the statements that `measure-one` counted for the cold request, which
is not subject to the limit (120,004 statements, 835,196 rows): the two ways of counting agree to within about 30 statements and 11 rows.

Against the estimate (`../../estimate/estimate.json`, 119,994 statements and 1,489,446 rows for this route; 1,491,966 rows on the safe
side; the converted CPU is `c_q * statements + c_row * rows` with the committed prediction's coefficients, 36.71 s point and 36.71 s safe side):

| estimate ÷ measured | limit on | limit off |
| --- | --- | --- |
| statements | 1.25 | 1.00 |
| rows | 1.86 | 1.78 |
| database CPU time (point / safe side) | 1.46 / 1.46 | 1.10 / 1.10 |

The statements estimate and the converted CPU come close to the request that was not cut; the rows estimate is about 1.8 times what the
server read in either case.

## Other things the record shows

- No other request was seen by the web server during any of the requests of either record (the column is 0 in every row of both `http.jsonl` files).
- A `SHOW FULL PROCESSLIST` taken after each window (as the user the probe already uses; no grant was added) shows one row, the event
  scheduler's daemon, and no connection of the app: the cut request's connection was closed as well.
- The row counts of the three N tables were written at the start (`unlimited-100000/table-counts.json`): 100,000 each.
- `handler reads` of the limit-on record is below the limit-off one by about 4%, and the statements by 20%: what the cut left undone was
  mostly statements that read few rows each. The database CPU time of the two differs by 25%.

## Files

| File | |
| --- | --- |
| `settings-1-before.txt`, `settings-2-unlimited.txt`, `settings-3-after.txt` | the settings read at the three times, with the commands' own output |
| `unlimited-100000/` | the record of the three requests without the limit (`http.jsonl`, `run.log`, `context.json`, `table-counts.json`) |
| `compare.json`, `compare.md` | the limit-on record (`../per-request/100000/`) and the limit-off record, side by side (`request_probe.py compare`) |
