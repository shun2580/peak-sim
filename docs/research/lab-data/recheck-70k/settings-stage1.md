# Lab settings: first read on the real lab, then the limit removed (2026-11-11)

Lab pointed at the 10,000-row database throughout; no request was sent that counts as a measurement.

## 1. First read on the real lab: `check-limits --require=none` (14:32:41)

```
LIMITS OK (require none): php_max_execution_time=30, php_fpm_request_terminate_timeout=0s, nginx_fastcgi_read_timeout=120s,
k6_request_timeout=null (not given: the load tool used its own default, 60 s for k6 2.3.0)   exit 0
```

## 2. Limits removed (same procedure as the switch rehearsal)

| | before (14:32:41) | after (14:33:09) |
| --- | --- | --- |
| `max_execution_time` seen through php-fpm (`ini-probe.php`) | 30 | 0 |
| `request_terminate_timeout` (`php-fpm -tt`) | 0s | 0s |
| `request_slowlog_timeout` (`php-fpm -tt`) | (not read) | 0s |
| nginx `fastcgi_read_timeout` (`nginx -T`, web container) | 120s | 600s |
| `zz-lab.ini` SHA-256 | f495eab5...c261b | 4925ce15...29ae9 (host = container) |
| `lab/conf` (`nginx/lab.conf`) SHA-256 | 604aedff...55ec5 | fb4856ea...207fa (host = container) |
| `zz-lab-fpm.conf` SHA-256 | 27668d61...1f66 | 27668d61...1f66 (untouched) |
| app / web container start (UTC) | 04:55:17 | 05:32:50 (recreated by `use-db 10000`) |
| db container start (UTC) | 04:55:10 | 04:55:10 (unchanged) |
| `PEAK_LAB_SCALE` | 10000 | 10000 |

Changed files: `poc/lab/compose/php/zz-lab.ini` (a comment line and `max_execution_time=0` appended) and `poc/lab/compose/nginx/lab.conf`
(`fastcgi_read_timeout 120s;` -> `600s;`). Originals kept outside the repository. `use-db 10000` ran the closed-network check: PREFLIGHT OK.

## 3. `check-limits --require=unlimited --request-timeout=600s` (14:33:09)

```
LIMITS OK (require unlimited): php_max_execution_time=0, php_fpm_request_terminate_timeout=0s, nginx_fastcgi_read_timeout=600s,
k6_request_timeout=600s   exit 0
```

Times: change start 14:32:48, `use-db` end 14:33:03. php-fpm's own log time is UTC (05:33:09 = 14:33:09 JST).
