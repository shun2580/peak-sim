# Load tools of the lab backtest

What sends the load and what counts it (`docs/simulator-spec.md` section 7.2). The environment (compose, start and stop, the
closed-network check) is elsewhere in `poc/lab/`; the route list is in `poc/lab/routes/`.

| File | What it does |
| --- | --- |
| `make-plan.php` | Route list + mix (`even`, `first80`, `last80`, `odd80`, `even80` (80% on the 2nd, 4th, 6th and 8th routes), `places1235` / `places1345` (80% on the 1st, 2nd, 3rd and 5th / the 1st, 3rd, 4th and 5th routes), or `bookpage80zip0`: 80% on `GET /books/{slug}`, none on the ZIP export, the rest shared equally; the two routes are found by name, and the ZIP route is left out of the plan) + total rps -> the plan: one rate per route, in the order of the list. |
| `scenario.js` | The k6 script. One constant-arrival-rate stream per route, GET only. Tags each request `phase=warmup` (the discarded start) or `phase=measure` and writes a summary file (the HTTP status counts overall and by route). The optional environment value `REQUEST_TIMEOUT` (a k6 duration with a unit, such as `600s`) is passed to every request; without it the requests are sent as before and k6's own default applies (60 s in k6 2.3.0). A value that is not a duration stops the script before any request. With `REQUEST_TIMEOUT` the script also waits that long at the end of the scenario for the requests still running (k6's own wait is 30 s), and the summary holds the count of the requests **sent** inside the kept window (`measure_sent`, by route `per_route_sent`), how many of the window's requests finished after its end (`finished_after_window`, `longest_after_window_ms`) and the dropped arrivals (`dropped_iterations`). It also writes, by route, the number of requests **running** (sent before the instant, not answered until at or after it) at the window's first instant and at its last (`running_at_window_start`, `running_at_window_end`), counted on the load tool's sending side and not in php-fpm (`running_counted_by` says so): a reading of whether the load had reached a steady state, never judged (simulator-spec.md section 7.4.5); `summarize-run.php` and the step's record carry it (`running_requests`) with the step's discarded start (`warmup_sec`). The discarded start of a step is the one the prediction file states for it (`measure-ladder` reads `warmup_sec` per stage and stops before any container when it is missing); `ladder.php shape --warmup=<s>` makes a step of that preparation. |
| `summarize-run.php` | Plan + k6 summary + CPU samples -> the record of one scenario: did the rps come out within 5% (`valid` / `invalid`), the DB utilization over the kept window, requests per route. The rps is counted on the requests **sent** in the window, and a step in which the load tool dropped any arrival is invalid (`docs/simulator-spec.md` section 7.4.3); `rps.counted` says `sent`, or `completed` for a summary written before the sent count existed (`--require-sent=1` refuses that). |
| `ladder.php` | The command line of `bin/measure-ladder`'s own parts: the steps, the scenarios (`t-1`..`t-5`, the re-check's `r-1`..`r-5` and the third backtest's `s-1`..`s-5`; `rungs --scenario=<id>` gives the 15 steps of `s-*`), the stopping rule, the record of one step, the three limits worked out from what was read in the containers (`settings`), the dry-run table. `reread --dir=<scenario dir>` reads the recorded steps of a scenario again with the section 7.4.3 rule and prints the old verdict beside the new one (it writes nothing). Measures nothing. |
| `../observe/sample-cpu` | Reads the DB container's `cpu.stat` every few seconds into a file. Stops with no figure if it cannot read it. |

`summarize-run.php` also takes `--app-samples=<file>` (the app container sampled the same way; `--app-cpus`, default 6), which adds the app's CPU utilization over the same window, and passes the k6 summary's HTTP status counts (`http_status`, and `http_status_by_route` when k6 wrote it) and the timeout k6 was given (`request_timeout`) through.

The `failed` count in a record is the requests that were not 2xx; the rps check counts every request sent in the window whatever its status. One route (`GET /oidc/callback`, sent as a guest) answers 302 by design, so its requests show as `failed`.

Utilization = change in `usage_usec` / (change in time x 2 CPUs), over the kept 8 minutes, never an instant (`docs/decisions.md` D144).

```bash
php poc/lab/load/make-plan.php --routes=poc/lab/routes/route-list.json --mix=first80 --rps=40 > plan.json
# k6 reads PLAN, TARGETS ({"base_url", "headers", "routes": {"GET /x/{id}": {"path": "/x/1"}}}) and SUMMARY
k6 run scenario.js
php poc/lab/load/summarize-run.php --plan=plan.json --k6-summary=summary.json --samples=cpu.txt
```

**The end of a step.** A request that is still running when the window ends is waited for (as long as the request timeout) instead of being cut. The database work it does while it is waited for is after the kept window and is not in the step's database utilization; each record says so in `end_of_step`.

The tests are in `poc/runtime/tests/Unit/LabLoad/` (suite `RuntimeUnit`); the parts are in `poc/runtime/src/LabLoad/`. `poc/lab/tests/sent-count.sh` runs the real k6 against a stand-in server to show the count and the wait of section 7.4.3.
