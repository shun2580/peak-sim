| look | time | Recv-Q (:9000) | ESTAB in app | ESTAB web->:9000 | workers by wchan | worker sockets with data waiting | unaccepted ESTAB | stub_status Active | k6 running line | app% | db% |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| w-1min | 2026-11-26 04:43:32 | 213 | 229 | 229 | 9×0; 3×do_sys_poll; 4×wait_woken | - | - | 238 | 08m56.8s VUs 0221/0238 complete 16885 | 637.50 | 80.72 |
| end+5s | 2026-11-26 04:44:37 | 217 | 233 | 234 | 7×0; 2×do_sys_poll; 7×wait_woken | - | - | 246 | 10m00.8s VUs 0219/0245 complete 18902 | 210.67 | 5.03 |
| end+1m | 2026-11-26 04:45:32 | 218 | 234 | 234 | 16×wait_woken | - | - | 246 | 10m56.8s VUs 0218/0245 complete 18903 | 1.92 | 0.32 |
| end+2m | 2026-11-26 04:46:32 | 202 | 218 | 218 | 16×wait_woken | 16 held by workers (0 with unread bytes) | 202 | 219 | 11m56.8s VUs 0202/0245 complete 18919 | 0.00 | 0.30 |
| end+5m | 2026-11-26 04:49:32 | 154 | 170 | 170 | 16×wait_woken | 16 held by workers (0 with unread bytes) | 154 | 171 | 14m56.8s VUs 0154/0245 complete 18967 | 0.00 | 0.31 |
| end+10m | 2026-11-26 04:54:32 | 74 | 90 | 90 | 16×wait_woken | 16 held by workers (0 with unread bytes) | 74 | 91 | 19m57.8s VUs 0074/0245 complete 19047 | 0.00 | 0.28 |
| tail-end | 2026-11-26 04:58:41 | 0 | 10 | 10 | 6×inet_csk_accept; 10×wait_woken | 10 held by workers (0 with unread bytes) | 0 | 1 | 24m02.8s VUs 0000/0245 complete 19121 | 0.00 | 22.26 |
