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
//                    With it, k6 also waits this long at the end of the scenario for the requests that are still running (k6's
//                    own graceful stop is 30 s): a slow request that was sent inside the window is not cut off and forgotten.
//                    The database work of such a request happens after the kept window and is outside it (section 7.4.3).
//
// What is counted for "did the decided rps come out" is the requests SENT inside the kept window (lab_sent{phase:measure}), not the
// ones that had finished by then, and the arrivals the load tool dropped (dropped_iterations, which must be 0). Section 7.4.3.
// A request sent at or after the window's end is tagged phase=after and counted nowhere in the check (phase.js).
//
// Also written down, by route and never judged (section 7.4.5): how many requests were RUNNING at the window's first instant and at its last
// instant, that is sent before that instant and not answered until at or after it (lab_running_at_window_start / lab_running_at_window_end).
// They are counted here, on the load tool's sending side (each request is timed from its own send to its answer), not in php-fpm; the summary
// says so (running_counted_by). They show whether the load had reached a steady state when the window opened and when it closed.
//
// It sends no write: a route that is not a GET is refused before any request is made.
import http from 'k6/http';
import exec from 'k6/execution';
import { Counter, Gauge } from 'k6/metrics';
import { phaseAt } from './phase.js';

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
// Counted when a request is sent (before the answer), tagged with the phase and the route it was sent in.
const sentCount = new Counter('lab_sent');
// A request of the kept window that finished after the window's end, and how long after (the Gauge keeps the largest).
const afterWindowCount = new Counter('lab_finished_after_window');
const afterWindowMs = new Gauge('lab_after_window_ms');
// A request that was sent before the window's first (last) instant and was still without an answer at it, tagged with its route.
const runningAtStart = new Counter('lab_running_at_window_start');
const runningAtEnd = new Counter('lab_running_at_window_end');

http.setResponseCallback(http.expectedStatuses({ min: 200, max: 299 }));

// The statuses counted one by one (the HTTP breakdown of the record); any other status is counted as `other`.
const STATUSES = [200, 201, 204, 301, 302, 303, 304, 307, 308, 400, 401, 403, 404, 405, 408, 409, 410, 422, 429, 500, 501, 502, 503, 504, 0];

const scenarios = {};
const thresholds = {
  'http_reqs{phase:measure}': ['count>=0'],
  'http_req_failed{phase:measure}': ['rate>=0'],
  'http_req_duration{phase:measure}': ['max>=0'],
  'lab_sent{phase:measure}': ['count>=0'],
  'lab_sent{phase:after}': ['count>=0'],
  'lab_finished_after_window{phase:measure}': ['count>=0'],
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
  // The wait at the end for the requests still running is the request timeout (section 7.4.3); without a timeout k6's default stays.
  if (requestTimeout !== undefined) {
    scenarios[name].gracefulStop = requestTimeout;
  }
  thresholds['http_reqs{phase:measure,route:' + r.route + '}'] = ['count>=0'];
  thresholds['lab_sent{phase:measure,route:' + r.route + '}'] = ['count>=0'];
  thresholds['lab_running_at_window_start{route:' + r.route + '}'] = ['count>=0'];
  thresholds['lab_running_at_window_end{route:' + r.route + '}'] = ['count>=0'];
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
  const sentAt = Date.now();
  const phase = phaseAt(sentAt - started, plan.warmup_sec, plan.duration_sec);
  scenarioStart.add(started);
  const params = {
    headers: Object.assign({}, targets.headers || {}, t.headers || {}),
    redirects: 0,
    tags: { phase: phase, route: r.route, name: r.route },
  };
  if (requestTimeout !== undefined) {
    params.timeout = requestTimeout;
  }
  sentCount.add(1, { phase: phase, route: r.route });
  http.get(targets.base_url + t.path, params);
  const windowStart = started + plan.warmup_sec * 1000;
  const windowEnd = started + plan.duration_sec * 1000;
  const now = Date.now();
  // running at an instant: sent before it, answered at or after it
  if (sentAt < windowStart && now >= windowStart) {
    runningAtStart.add(1, { route: r.route });
  }
  if (sentAt < windowEnd && now >= windowEnd) {
    runningAtEnd.add(1, { route: r.route });
  }
  if (phase === 'measure' && now > windowEnd) {
    afterWindowCount.add(1, { phase: phase });
    afterWindowMs.add(now - windowEnd);
  }
}

function metric(data, key) {
  const m = data.metrics[key];
  return m ? m.values : null;
}

export function handleSummary(data) {
  const per_route = {};
  const per_route_sent = {};
  const running_at_window_start = {};
  const running_at_window_end = {};
  for (const r of plan.routes) {
    const rs = metric(data, 'lab_running_at_window_start{route:' + r.route + '}');
    running_at_window_start[r.route] = rs ? rs.count : 0;
    const re = metric(data, 'lab_running_at_window_end{route:' + r.route + '}');
    running_at_window_end[r.route] = re ? re.count : 0;
    const v = metric(data, 'http_reqs{phase:measure,route:' + r.route + '}');
    per_route[r.route] = v ? v.count : 0;
    const sv = metric(data, 'lab_sent{phase:measure,route:' + r.route + '}');
    per_route_sent[r.route] = sv ? sv.count : 0;
  }
  const sentMeasure = metric(data, 'lab_sent{phase:measure}');
  const sentAfter = metric(data, 'lab_sent{phase:after}');
  const afterWindow = metric(data, 'lab_finished_after_window{phase:measure}');
  const afterMs = metric(data, 'lab_after_window_ms');
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
    measure_sent: sentMeasure ? sentMeasure.count : 0,
    sent_after_window: sentAfter ? sentAfter.count : 0,
    finished_after_window: afterWindow ? afterWindow.count : 0,
    longest_after_window_ms: afterMs ? afterMs.max : 0,
    graceful_stop: requestTimeout === undefined ? null : requestTimeout,
    measure_failed: failed ? failed.passes : 0,
    measure_ok: failed ? failed.fails : 0,
    dropped_iterations: dropped ? dropped.count : 0,
    http_status: http_status,
    http_status_by_route: http_status_by_route,
    request_timeout: requestTimeout === undefined ? null : requestTimeout,
    duration_ms: dur ? { avg: dur.avg, p95: dur['p(95)'], max: dur.max } : null,
    per_route: per_route,
    per_route_sent: per_route_sent,
    running_at_window_start: running_at_window_start,
    running_at_window_end: running_at_window_end,
    running_counted_by: 'k6 sending side: requests sent before the instant and not answered until at or after it, timed by the load tool (not php-fpm)',
  };
  const files = {};
  files[summaryPath] = JSON.stringify(out, null, 2) + '\n';

  return files;
}
