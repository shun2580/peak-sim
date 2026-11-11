// k6 scenario of the lab backtest (simulator-spec.md section 7.2). It sends GET requests at a constant arrival rate, one stream
// per route, so the mix of the routes is exact. The first part of the scenario (the discarded start) is tagged phase=warmup and
// the rest phase=measure; only the measure part is counted for the rps check.
//
// Inputs (files, read once at start; paths come from the environment):
//   PLAN     the plan made by `make-plan`: the routes, the rate of each, the length and the discarded start
//   TARGETS  where each route is sent: {"base_url": "http://...", "headers": {...}, "routes": {"GET /x/{id}": {"path": "/x/1"}}}
//   SUMMARY  where the run's summary is written (JSON, read by `summarize-run`)
//
// Optional input (environment):
//   REQUEST_TIMEOUT  how long k6 waits for one response before it gives up (a k6 duration such as 600s). Without it the requests are
//                    sent exactly as before and k6's own default applies; with it the value is passed on every request, so a run that
//                    must not be cut by k6 before the server's own limits says its value instead of relying on the default.
//
// It sends no write: a route that is not a GET is refused before any request is made.
import http from 'k6/http';
import exec from 'k6/execution';
import { Gauge } from 'k6/metrics';

const plan = JSON.parse(open(__ENV.PLAN));
const targets = JSON.parse(open(__ENV.TARGETS));
const summaryPath = __ENV.SUMMARY;

if (!summaryPath) {
  throw new Error('SUMMARY is not set');
}
// A number is milliseconds in k6; a string needs a unit. Anything else is refused here, before a request is made, so that a typo
// is not silently replaced by k6's default.
const requestTimeout = __ENV.REQUEST_TIMEOUT;
if (requestTimeout !== undefined && !/^[0-9]+(\.[0-9]+)?(ms|s|m|h)$/.test(requestTimeout)) {
  throw new Error('REQUEST_TIMEOUT must be a k6 duration such as 600s (got "' + requestTimeout + '")');
}
for (const r of plan.routes) {
  if (!r.route.startsWith('GET ')) {
    throw new Error('only GET routes are loaded: ' + r.route);
  }
  if (!targets.routes[r.route] || !targets.routes[r.route].path) {
    throw new Error('no target for route: ' + r.route);
  }
}

const scenarioStart = new Gauge('lab_scenario_start_ms');

http.setResponseCallback(http.expectedStatuses({ min: 200, max: 299 }));

// The statuses counted one by one (the HTTP breakdown of the record); any other status is counted as `other`.
const STATUSES = [200, 201, 204, 301, 302, 303, 304, 307, 308, 400, 401, 403, 404, 405, 408, 409, 410, 422, 429, 500, 501, 502, 503, 504, 0];

const scenarios = {};
const thresholds = {
  'http_reqs{phase:measure}': ['count>=0'],
  'http_req_failed{phase:measure}': ['rate>=0'],
  'http_req_duration{phase:measure}': ['max>=0'],
};
for (const status of STATUSES) {
  thresholds['http_reqs{phase:measure,status:' + status + '}'] = ['count>=0'];
}
plan.routes.forEach((r, i) => {
  // The same breakdown for each route: which route got which status (a route cut by a limit shows as 500 or 0 only on that route).
  for (const status of STATUSES) {
    thresholds['http_reqs{phase:measure,route:' + r.route + ',status:' + status + '}'] = ['count>=0'];
  }
  const name = 'r' + i;
  scenarios[name] = {
    executor: 'constant-arrival-rate',
    rate: r.rate,
    timeUnit: r.time_unit,
    // A route's stream may start later than the others (a plan made for a staggered scheme gives it `start_offset_sec`); every
    // stream ends at the end of the scenario, so its duration is what is left after its start. Without the field: starts at 0.
    startTime: (r.start_offset_sec || 0) + 's',
    duration: (plan.duration_sec - (r.start_offset_sec || 0)) + 's',
    preAllocatedVUs: parseInt(__ENV.PRE_VUS || '20'),
    maxVUs: parseInt(__ENV.MAX_VUS || '400'),
    exec: 'hit',
    env: { ROUTE_INDEX: String(i) },
  };
  thresholds['http_reqs{phase:measure,route:' + r.route + '}'] = ['count>=0'];
});

export const options = {
  scenarios,
  thresholds,
  discardResponseBodies: true,
  summaryTrendStats: ['avg', 'p(95)', 'max'],
};

export function hit() {
  const r = plan.routes[parseInt(__ENV.ROUTE_INDEX)];
  const t = targets.routes[r.route];
  // The start of the whole scenario (not of this route's stream, which may start later): for a stream that starts at 0 it is the
  // stream's own start; for a later one, the time since the test began gives it.
  const started = r.start_offset_sec ? Date.now() - exec.instance.currentTestRunDuration : exec.scenario.startTime;
  const phase = Date.now() - started < plan.warmup_sec * 1000 ? 'warmup' : 'measure';
  scenarioStart.add(started);
  const params = {
    headers: Object.assign({}, targets.headers || {}, t.headers || {}),
    redirects: 0,
    tags: { phase: phase, route: r.route, name: r.route },
  };
  if (requestTimeout !== undefined) {
    params.timeout = requestTimeout;
  }
  http.get(targets.base_url + t.path, params);
}

function metric(data, key) {
  const m = data.metrics[key];
  return m ? m.values : null;
}

export function handleSummary(data) {
  const per_route = {};
  for (const r of plan.routes) {
    const v = metric(data, 'http_reqs{phase:measure,route:' + r.route + '}');
    per_route[r.route] = v ? v.count : 0;
  }
  const reqs = metric(data, 'http_reqs{phase:measure}');
  const failed = metric(data, 'http_req_failed{phase:measure}');
  const dropped = metric(data, 'dropped_iterations');
  const start = metric(data, 'lab_scenario_start_ms');
  const dur = metric(data, 'http_req_duration{phase:measure}');
  const http_status = {};
  let counted = 0;
  for (const status of STATUSES) {
    const v = metric(data, 'http_reqs{phase:measure,status:' + status + '}');
    if (v && v.count > 0) {
      http_status[String(status)] = v.count;
      counted += v.count;
    }
  }
  const total = reqs ? reqs.count : 0;
  if (total > counted) {
    http_status.other = total - counted;
  }
  // per route: { "GET /x": {"200": n, "500": m, "other": k}, ... }; a route that got no request has an empty object
  const http_status_by_route = {};
  for (const r of plan.routes) {
    const byStatus = {};
    let routeCounted = 0;
    for (const status of STATUSES) {
      const v = metric(data, 'http_reqs{phase:measure,route:' + r.route + ',status:' + status + '}');
      if (v && v.count > 0) {
        byStatus[String(status)] = v.count;
        routeCounted += v.count;
      }
    }
    const routeTotal = per_route[r.route];
    if (routeTotal > routeCounted) {
      byStatus.other = routeTotal - routeCounted;
    }
    http_status_by_route[r.route] = byStatus;
  }
  const out = {
    scenario_start_ms: start ? start.min : null,
    measure_requests: reqs ? reqs.count : 0,
    measure_failed: failed ? failed.passes : 0,
    measure_ok: failed ? failed.fails : 0,
    dropped_iterations: dropped ? dropped.count : 0,
    http_status: http_status,
    http_status_by_route: http_status_by_route,
    request_timeout: requestTimeout === undefined ? null : requestTimeout,
    duration_ms: dur ? { avg: dur.avg, p95: dur['p(95)'], max: dur.max } : null,
    per_route: per_route,
  };
  const files = {};
  files[summaryPath] = JSON.stringify(out, null, 2) + '\n';

  return files;
}
