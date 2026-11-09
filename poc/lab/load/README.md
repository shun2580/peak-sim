# Load tools of the lab backtest

What sends the load and what counts it (`docs/simulator-spec.md` section 7.2). The environment (compose, start and stop, the
closed-network check) is elsewhere in `poc/lab/`; the route list is in `poc/lab/routes/`.

| File | What it does |
| --- | --- |
| `make-plan.php` | Route list + mix (`even`, `first80`, `last80`, `odd80`) + total rps -> the plan: one rate per route, in the order of the list. |
| `scenario.js` | The k6 script. One constant-arrival-rate stream per route, GET only. Tags each request `phase=warmup` (the discarded start) or `phase=measure` and writes a summary file. |
| `summarize-run.php` | Plan + k6 summary + CPU samples -> the record of one scenario: did the rps come out within 5% (`valid` / `invalid`), the DB utilization over the kept window, requests per route. |
| `ladder.php` | The command line of `bin/measure-ladder`'s own parts: the steps, the scenarios, the stopping rule, the record of one step, the dry-run table. Measures nothing. |
| `../observe/sample-cpu` | Reads the DB container's `cpu.stat` every few seconds into a file. Stops with no figure if it cannot read it. |

`summarize-run.php` also takes `--app-samples=<file>` (the app container sampled the same way; `--app-cpus`, default 6), which adds the app's CPU utilization over the same window, and passes the k6 summary's HTTP status counts (`http_status`) through.

The `failed` count in a record is the requests that were not 2xx; the rps check counts every request in the window whatever its status. One route (`GET /oidc/callback`, sent as a guest) answers 302 by design, so its requests show as `failed`.

Utilization = change in `usage_usec` / (change in time x 2 CPUs), over the kept 8 minutes, never an instant (`docs/decisions.md` D144).

```bash
php poc/lab/load/make-plan.php --routes=poc/lab/routes/route-list.json --mix=first80 --rps=40 > plan.json
# k6 reads PLAN, TARGETS ({"base_url", "headers", "routes": {"GET /x/{id}": {"path": "/x/1"}}}) and SUMMARY
k6 run scenario.js
php poc/lab/load/summarize-run.php --plan=plan.json --k6-summary=summary.json --samples=cpu.txt
```

The tests are in `poc/runtime/tests/Unit/LabLoad/` (suite `RuntimeUnit`); the parts are in `poc/runtime/src/LabLoad/`.
