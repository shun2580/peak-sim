# Diagnosis tools of the lab backtest

Tools for looking at what one request costs in the lab, for the diagnosis of the backtest's miss. They send one request at a time and
never change the lab: no setting, no data, no container. They are not part of the backtest's measurement and change none of its tools.

| File | What it does |
| --- | --- |
| `request_probe.py` | `run`: per route, one warm-up request and N (default 3) requests, one at a time, through the web server and php-fpm. Around each one it reads the database container's `cpu.stat` (`usage_usec`) and the server's global counters (`Handler_read_*`, `Questions`, ...), and the app container's `cpu.stat` (the PHP side). It also runs `measure-one` (the measurement's own process: a warm-up request, then the cold one) N times per route, which gives the statements and the rows the database looked at (the session's `Handler_read_*`; D96, D101, D102). `report`: sets the records beside an estimate (point and safe side) and the calibration's coefficients and works out `estimate / measured`. |
| `test_request_probe.py` | tests of the pure parts (parsing, the empty-window and idle-drift correction, the floor, the ratios). `python3 -m unittest poc/lab/diagnosis/test_request_probe.py`; no Docker. |
| `fcgi_get.php`, `ini-probe.php` | read a setting **through php-fpm** (the CLI's `php -i` shows the CLI's own values): a minimal FastCGI client run in the tools container, and a script it asks php-fpm to run. `ini-probe.php` opens no database connection; with `mode=sleep` / `mode=spin` it shows whether the execution-time limit counts waiting or CPU time. |

## How a window is read, and what it is corrected by

- Reading `cpu.stat` with `docker exec` runs a process inside the container's cgroup, so each reading adds some CPU time. A **null window**
  (the two readings back to back, five times) gives that, and its median is taken off every request window.
- The database is never quite idle (MySQL's own background work). An **idle reading** (ten quiet seconds) gives that rate; the rate times
  the window's extra length is taken off as well. A reading that is no larger than three times the spread of the null windows, or than the
  idle drift taken off it, is written as **below the measurement floor** and no ratio is made on it.
- The window closes after php-fpm has finished, not when the client got its answer: for an answer that was not a quick success the tool
  waits until the app container's CPU time stops growing (it polls the app container only, never the database's, so the polls add nothing
  to the database's window). A quick, complete answer closes the window at once.
- "Other requests" is the web server's request counter between the two probes less the two probes themselves; it must be 0.
- The cold request of `measure-one` flushes the app's cache after its warm-up; a request through php-fpm boots the app from nothing every
  time (the cache is an array store made at boot), so what php-fpm does is the first request of a process, which is one or two statements more
  than the cold one. The table uses the cold one (what the estimate was made from) and keeps both.
