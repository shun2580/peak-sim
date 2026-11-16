# Mark measurement at 300,000 rows

Host clock, 2026-11-16. No pass or fail is written here.

- Before the mark, the lab held the B settings (`max_execution_time` 0, `memory_limit` 4G, `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 600s). `limits-before-restore/` is `check-limits --require=unlimited --request-timeout=600s` read at 17:24 (LIMITS OK). The app container's logs and the web container's logs were copied before the containers were made again.
- 17:24:39 the two compose files were put back from the copies kept outside the repository (`orig-sha256.txt`; `compose-sha256-after-restore.txt` holds the same two hashes). `git diff -- poc/lab/compose` was 0 lines.
- 17:24:44 `bin/use-db 300000` rebuilt the app and web containers (exit 0, PREFLIGHT OK). The app container's memory limit (`HostConfig.Memory`) stayed 6442450944.
- `limits-after-restore/`: `check-limits --require=limited` exit 0: `max_execution_time` 30, `memory_limit` 512M, `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 120s.
- `request_probe.py run --scale=300000 --part=http --reps=1 --require-limits=limited` ran 17:25:11 to 17:27:24 (exit 0): per route one warm-up (rep 0) and one request (rep 1), one at a time, k6 timeout 180 s (the default, as in the 50,000 and 200,000 marks). `mark-300000/` holds the record; the warm-up results are kept too. The `label` field of `context.json` was shortened to `mark 300000` for the repository (it named an internal task); nothing else in the record was changed.
- `stats-mark-300000.tsv`: `innodb_table_stats` (last_update, n_rows) of the 300,000-row database read before and after (`before-mark-300000`, `after-mark-300000`); the two readings are identical, and no ANALYZE was run.

## Status of each request

| route | rep 0 (warm-up) | rep 1 |
|---|---|---|
| `GET /books/{bookSlug}/chapter/{chapterSlug}/references` | 200, 2345 ms | 200, 35 ms |
| `GET /books/{bookSlug}/export/zip` | 500, 42460 ms | 500, 41737 ms |
| `GET /books/{bookSlug}/page/{pageSlug}/references` | 200, 41 ms | 200, 26 ms |
| `GET /books/{slug}` | 500, 5000 ms | 500, 4640 ms |
| `GET /books/{slug}/references` | 200, 38 ms | 200, 26 ms |
| `GET /images/edit/{id}` | 200, 98 ms | 200, 22 ms |
| `GET /oidc/callback` | 302, 84 ms | 302, 13 ms |
| `GET /shelves/{slug}` | 200, 160 ms | 200, 38 ms |

## Which limit ended each 500

`app-log-after-mark.txt` is the app container's log from the start of the container to the end of the mark. It holds four lines `PHP Fatal error:  Allowed memory size of 536870912 bytes exhausted` (the 512M `memory_limit`), at 08:26:14, 08:26:58, 08:27:08 and 08:27:15 UTC. These are the same four moments as the four 500 answers: the two requests of `GET /books/{bookSlug}/export/zip` (17:26:16 and 17:27:01 on the host clock, 9 hours ahead of UTC) and the two of `GET /books/{slug}` (17:27:10 and 17:27:17). No line of the log shows the 30-second `max_execution_time` or the nginx 120 s limit ending a request.

sha256 of the three files of `mark-300000/` when they were committed: `context.json` d6f0b0ce0248f1dfd35af502307d7d0d4de80a1f8fbacb7ba7b7b3c0dd79b129, `http.jsonl` f3541134fa560a38891f32ae8f7cf089699447a20f21e8f6fe18c2287c53c75d, `table-counts.json` ac00ece26df217339cc1a16d10dd2558060e68782472b3a8e50e2d2c4af231aa.
