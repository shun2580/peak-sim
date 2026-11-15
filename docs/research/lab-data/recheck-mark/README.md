# Mark measurement (cmd_277 T6), 50,000 rows

Host clock, 2026-11-15. No pass or fail is written here.

- 17:19:49 `limits-before-restore` (kept in `../recheck-ladder-b/limits-after-b/`): B settings still in place (`max_execution_time` 0, `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 600s).
- 17:20 the two compose files were put back from the copies kept outside the repository (`orig-sha256.txt`; `compose-sha256-after-restore.txt` is the same two hashes); `bin/use-db 50000` rebuilt the app and web containers (exit 0).
- `limits-after-restore/`: `check-limits --require=limited` exit 0: `max_execution_time` 30 (one request through php-fpm), `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 120s.
- `request_probe.py run --scale=50000 --part=http --reps=1 --require-limits=limited` ran 17:20:27 to 17:22:29 (exit 0): per route one warm-up (rep 0) and one request (rep 1), one at a time. `mark-50000/` holds the record, `mark-50000.log` the log. The warm-up results are kept too.
- `stats-mark-50000.tsv`: `innodb_table_stats` (last_update, n_rows) read before and after; no ANALYZE was run.
- `limits-at-stop/`: the same reading at 17:26:55, when the lab was on the 200,000-row database with the limit at 30 seconds. `git diff -- poc/lab/compose` was 0 lines then.

## Mark measurement at 200,000 rows

Host clock, 2026-11-15. No pass or fail is written here.

- 17:23:38 `limits-mark-200000/`: `check-limits --require=limited` exit 0 on the 200,000-row database: `max_execution_time` 30, `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 120s (the same readings as `limits-after-restore/` and `limits-at-stop/`).
- `request_probe.py run --scale=200000 --part=http --reps=1 --require-limits=limited` ran 17:23:39 to 17:26:38: per route one warm-up (rep 0) and one request (rep 1), one at a time. `mark-200000/` holds the record (the log of the run is not kept in the repository; every request is in `http.jsonl`). The warm-up results are kept too.
- The two routes that answered 500 are `GET /books/{bookSlug}/export/zip` and `GET /books/{slug}`, in both reps (4 requests).
- `stats-mark-200000.tsv`: `innodb_table_stats` (last_update, n_rows) read before and after (the lines `before-mark-200000` and `after-mark-200000`); no ANALYZE was run. The same readings taken before the 50,000-row mark are in `stats-mark-50000.tsv`.
- sha256 of the three files of `mark-200000/` when they were committed: `context.json` a516afe9cf6f28d5308c73ff871d5b133dbe6cf6c991dd6d4ce9237b9b56a937, `http.jsonl` 4afc28a44f7c2767ad92f86ff06ab73174ae34cac0fd142d0dd0d100d6ef5f22, `table-counts.json` c3b4c171dfb9eca1fa0f82e109147282344fb80ed517d84f51ab9c52c598a86e.
