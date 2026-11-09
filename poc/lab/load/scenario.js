// k6 scenario of the lab backtest (simulator-spec.md section 7.2). It sends GET requests at a constant arrival rate, one stream
// per route, so the mix of the routes is exact. The first part of the scenario (the discarded start) is tagged phase=warmup and
// the rest phase=measure; only the measure part is counted for the rps check.
//
// Inputs (files, read once at start; paths come from the environment):
//   PLAN     the plan made by `make-plan`: the routes, the rate of each, the length and the discarded start
//   TARGETS  where each route is sent: {"base_url": "http://...", "headers": {...}, "routes": {"GET /x/{id}": {"path": "/x/1"}}}
//   SUMMARY  where the run's summary is written (JSON, read by `summarize-run`)
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

const scenarios = {};
const thresholds = {
  'http_reqs{phase:measure}': ['count>=0'],
  'http_req_failed{phase:measure}': ['rate>=0'],
  'http_req_duration{phase:measure}': ['max>=0'],
};
plan.routes.forEach((r, i) => {
  const name = 'r' + i;
  scenarios[name] = {
    executor: 'constant-arrival-rate',
    rate: r.rate,
    timeUnit: r.time_unit,
    duration: plan.duration_sec + 's',
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
  const started = exec.scenario.startTime;
  const phase = Date.now() - started < plan.warmup_sec * 1000 ? 'warmup' : 'measure';
  scenarioStart.add(started);
  http.get(targets.base_url + t.path, {
    headers: Object.assign({}, targets.headers || {}, t.headers || {}),
    redirects: 0,
    tags: { phase: phase, route: r.route, name: r.route },
  });
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
  const out = {
    scenario_start_ms: start ? start.min : null,
    measure_requests: reqs ? reqs.count : 0,
    measure_failed: failed ? failed.passes : 0,
    measure_ok: failed ? failed.fails : 0,
    dropped_iterations: dropped ? dropped.count : 0,
    duration_ms: dur ? { avg: dur.avg, p95: dur['p(95)'], max: dur.max } : null,
    per_route: per_route,
  };
  const files = {};
  files[summaryPath] = JSON.stringify(out, null, 2) + '\n';

  return files;
}
