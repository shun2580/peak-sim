# Mark measurement (cmd_277 T6), 50,000 rows

Host clock, 2026-11-15. No pass or fail is written here.

- 17:19:49 `limits-before-restore` (kept in `../recheck-ladder-b/limits-after-b/`): B settings still in place (`max_execution_time` 0, `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 600s).
- 17:20 the two compose files were put back from the copies kept outside the repository (`orig-sha256.txt`; `compose-sha256-after-restore.txt` is the same two hashes); `bin/use-db 50000` rebuilt the app and web containers (exit 0).
- `limits-after-restore/`: `check-limits --require=limited` exit 0: `max_execution_time` 30 (one request through php-fpm), `request_terminate_timeout` 0s, nginx `fastcgi_read_timeout` 120s.
- `request_probe.py run --scale=50000 --part=http --reps=1 --require-limits=limited` ran 17:20:27 to 17:22:29 (exit 0): per route one warm-up (rep 0) and one request (rep 1), one at a time. `mark-50000/` holds the record, `mark-50000.log` the log. The warm-up results are kept too.
- `stats-mark-50000.tsv`: `innodb_table_stats` (last_update, n_rows) read before and after; no ANALYZE was run.
- `limits-at-stop/`: the same reading at 17:26:55, when the lab was on the 200,000-row database with the limit at 30 seconds. `git diff -- poc/lab/compose` was 0 lines then.
