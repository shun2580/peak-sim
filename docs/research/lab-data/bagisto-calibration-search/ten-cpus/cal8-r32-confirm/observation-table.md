| look | time | Recv-Q (:9000) | ESTAB in app | ESTAB web->:9000 | workers by wchan | worker sockets with data waiting | unaccepted ESTAB | stub_status Active | k6 running line | app% | db% |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| w-1min | 2026-11-26 08:31:57 | 0 | 6 | 15 | 8×0; 1×do_sys_poll; 7×inet_csk_accept | 2 held by workers (2 with unread bytes) | 0 | 177 | 09m13.8s VUs 0013/0176 complete 17699 | 835.26 | 92.69 |
| end+5s | 2026-11-26 08:33:02 | 0 | 0 | 0 | 16×inet_csk_accept | 0 held by workers (0 with unread bytes) | 0 | 1 | 10m00.9s VUs 0000/0176 complete 19190 | 0.00 | 0.28 |
| end+1m | 2026-11-26 08:33:57 | 0 | 0 | 0 | 16×inet_csk_accept | 0 held by workers (0 with unread bytes) | 0 | 1 | 10m00.9s VUs 0000/0176 complete 19190 | 0.00 | 0.29 |
| end+2m | 2026-11-26 08:34:57 | 0 | 0 | 0 | 16×inet_csk_accept | 0 held by workers (0 with unread bytes) | 0 | 1 | 10m00.9s VUs 0000/0176 complete 19190 | 0.00 | 3.45 |
| end+5m | 2026-11-26 08:37:57 | 0 | 0 | 0 | 16×inet_csk_accept | 0 held by workers (0 with unread bytes) | 0 | 1 | 10m00.9s VUs 0000/0176 complete 19190 | 0.00 | 0.28 |
| end+10m | 2026-11-26 08:42:57 | 0 | 0 | 0 | 16×inet_csk_accept | 0 held by workers (0 with unread bytes) | 0 | 1 | 10m00.9s VUs 0000/0176 complete 19190 | 0.00 | 0.73 |
