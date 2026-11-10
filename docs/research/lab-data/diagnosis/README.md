# Diagnosis of the lab backtest's miss

Records made to find what the lab backtest's miss rests on. None of it is a prediction or a score: the backtest's own records (`../ladder/`, `../score/`, `poc/lab/predictions/`) and their pass/fail are unchanged, the estimate, the calibration and the formula are as committed, and nothing here is used to judge a scenario again.

| Directory | What it holds |
| --- | --- |
| `per-request/` | What one request costs, route by route, at 10,000, 30,000 and 100,000 rows: statements, rows read, database CPU time, HTTP status and elapsed time, beside the estimate (`estimate ÷ measured`). Read its `README.md` first: it says how a request was measured, lists every ratio that was withheld and why, and sets the database's own counters beside the counted statements. |
| `zip-limit/` | The ZIP export route at 100,000 rows with php's 30-second execution limit as it is (cut, HTTP 500) and with the limit taken off, three cold requests each, and the evidence, read through php-fpm at three times, that the setting was changed and put back. |
| `allocation/` | The sensitivity of the ladder prediction to the calibration's `c_row`. |
| `no-response/` | The requests that got no response (HTTP status 0) near 80% database utilisation in the ladder, and what that does to the utilisation reading. |

The tools that made `per-request/` and `zip-limit/` are in `poc/lab/diagnosis/` (`request_probe.py` and its tests, `fcgi_get.php` and `ini-probe.php` for reading a setting through php-fpm).

The lab was used by one person at a time for the records in `per-request/` and `zip-limit/`: one request at a time, no load, and no other tool or request in the lab's containers while a record was taken.
