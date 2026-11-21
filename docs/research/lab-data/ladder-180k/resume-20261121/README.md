# Resume of the B 180,000-row chain, 2026-11-21 (checks before the restart of s-1)

Written by the person who ran the lab (all times JST, taken from `date`; `docker logs` times are UTC, 9 hours behind). This is a record of the checks made before the chain was started again after the pause in `../PAUSE-20261121.md`. Nothing here is compared with the prediction, and nothing in the prediction, the estimate, the calibration, the formulas, the marking rules, the pass rules or the warm-up lengths was changed.

The chain and the watchdog were **not** started when this record was written; they wait for the go-ahead of the person who supervises the run.

## What was done (steps 0 to 3 of "How to resume")

| step | time | what | result |
|---|---|---|---|
| 0 | 23:55-23:56 | `docker info`; `docker volume ls`; `docker ps -a --filter name=peak-b180k` | docker works; `peak-b180k-dbdata` listed; five containers `Exited` (web 0, app 0, db 0, k6 137, tools 137) |
| 1 | 23:56 | in the run's copy of the repo: `git status`, sha256 of the two B compose files, `lab-config-b status` | only the two B files are modified; both sha256 equal the values in the pause record; `STATE B` (`apply` was not run) |
| 1 | 23:56 | `ps` for chain, measure, k6, build, watchdog processes | 0 lines |
| 2 | 23:56:18 | the old s-1, `chain`, `chain-ends.txt`, `chain-s-1.stdout` and `dblog-watch` moved aside with the name suffix `-INVALID-20261121` (moved, not deleted; `chain/STOP` went with `chain`) | the chain will write into a fresh `chain/` and `ladder/` |
| 3 | 23:56:22-23:56:43 | `bin/up` (`up-output.txt`) | `PREFLIGHT OK` for app, tools, web, k6 and db; lab up and sealed |
| 3.1 | 23:56-23:57 | `docker logs peak-b180k-db` (`db-log-after-up.txt`) | see below |
| 3.2 | 23:57:04 | `bin/use-db 180000` (`use-db-output.txt`) | exit 0, `PREFLIGHT OK` |
| 3.3 | 23:57:19 | `bin/check-limits --require=unlimited --request-timeout=1800s` (`check-limits-output.txt`, `check-limits-settings.json`) | `LIMITS OK`: `max_execution_time 0`, `memory_limit 4G`, `request_terminate_timeout 0s`, `fastcgi_read_timeout 1800s`, k6 request timeout 1800s |
| 3.4 | 23:57:25 | `bin/check-tables after` against the baseline of the old s-1 (`tables-baseline-from-old-s-1.tsv`, sha256 `77cc61037a8f...c3add8`; output `check-tables-output.txt`, `tables-after-resume.tsv`) | exit 16 from the write counters only; the three tables are the same (see below) |
| 3.4 | 23:57:38 | `bin/read-table-stats 180000 ... after-resume-before-s-1-remeasure` (`stats-after-resume.tsv`) | exit 0; 41 rows; update time and row estimate of all 41 tables equal the stats taken before the first start (`pause3b/table-stats-before-chain.tsv`, compared column by column without the time and label columns) |
| 4 (first half) | 23:57:47 | the db log saved again to `pause3b/db-log-before-chain.txt` (`db-log-before-chain-copy.txt`, `db-log-before-chain.txt.at`) | 70 lines; the old file (54 lines) is kept as `pause3b/db-log-before-chain-first-start-20261121.txt`; its 54 lines are the first 54 of the new one |

### Database log after the restart (3.1)

The previous shutdown was clean, so a normal start is expected. The log has the old 54 lines, the clean shutdown of the pause (`Received SHUTDOWN`, `Shutdown complete`, `MySQL Server - end.`, 12:44:05-12:44:06 UTC), and then a normal start at 14:56:26 UTC: `starting as process 1`, `InnoDB initialization has started` and `has ended` about one second apart, and `ready for connections` at 14:56:28 UTC. There is **no InnoDB crash-recovery line and no `[ERROR]`** after the restart; the only warnings are the usual self-signed certificate and pid-file ones. The container was `healthy` 22 seconds after `up`.

(The pattern the chain script looks for, `[ERROR]|ecover|starting as process|SHUTDOWN|Shutting down|Aborted|orrupt`, matches 5 lines of this 70-line file: the shutdown and the start lines of the pause and resume. That is why the saved copy was refreshed to 70 lines: the chain counts the lines already seen from this file, so those 5 lines are not flagged at its first check.)

### Tables and counters (3.4)

- **Rows and checksums of the three N tables are identical to the baseline of the old s-1**: `entities` 180000 / 3169663894, `images` 180000 / 1908525454, `references` 180000 / 1468974778 (a `diff` of the two files without the `write` lines is empty).
- The exit 16 comes only from the write counters. The counters in `performance_schema` start at the server's start, so after the restart the baseline's 41 counter lines (what the build and the first run wrote before the pause) are not there: the new reading has **0 counter lines**, i.e. no table has been opened or written since the restart. The tool's own note says that a restart of the server shows as a change. This is the kind of difference that a database restart always makes and is not counted as a miss (ruling of 2026-11-20 00:36, same as at the 170,000-row resume). It is recorded here and not hidden.
- A fresh baseline of the counters for the new s-1 is taken by the chain itself (`check-tables before` writes `ladder/s-1-180000/tables-before.tsv` because that file no longer exists after the move).

## Not changed

Nothing was run that changes the data: no `apply`, no ANALYZE, no request to the app. No `compose down`, no `-v`, no `volume rm`, no `prune`, no `--purge`, no `kill`. The production volumes (22 of them, `peak-lab-dbdata` and the others) and the `peak-lab` and `peak-t5a-*` projects were not touched.

## Next

The chain (`run-chain-watched.sh s-1 s-2 s-3 s-4 s-5`) and the watchdog are started only after the go-ahead, with the same prediction, the same request timeout and `--require-allocation` as the first start (2026-11-21T20:27:29).
