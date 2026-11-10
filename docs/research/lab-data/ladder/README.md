# Ladder measurements of the lab backtest (BookStack, 8 routes)

The records of the five prediction scenarios measured step by step up the rps ladder (`docs/simulator-spec.md` 7.2), with the layout `B` of a step (`poc/lab/bin/measure-ladder --scheme=B`): a window of the smallest multiple of 40 / rps that is at least 300 s, after 60 s that are discarded. The prediction file `poc/lab/predictions/ladder-prediction.json` was in `HEAD` (commit `5bdf6e88254439dd6127b39a503cf046384bac91`, 2026-11-10T10:18:07+09:00) before the first step of any scenario was started (the first step: 2026-11-10T10:19:58+09:00, scenario `t-4`); each `record.json` names that commit. Nothing in this directory is compared with the prediction.

Each directory `<scenario>-<scale>/` holds `ladder.json` (all the steps of the scenario) and one directory per step, `rung-NN-<rps>/`, with the plan that was sent (`plan.json`), the k6 summary, the raw CPU samples of the database and of the app container (`cpu.txt`, `app-cpu.txt`: epoch seconds and `usage_usec`), the start and end times, the summary of `load/summarize-run.php` (`run.json`) and the record (`record.json`). The k6 logs are not kept.

A scenario stops after the first step at 80% DB utilization or more, or whose rps did not come out (outside 5%). Every scenario below stopped on the first reason; no step had an rps outside 5%.

The order of work was by row count: `t-4` at 10,000 rows, `t-1` at 30,000, then `t-2`, `t-3` and `t-5` at 100,000 (the eight N tables all had that many rows each time; the statistics' automatic recalculation was left at its default).

## How to read the status counts

- `302` is the answer of `GET /oidc/callback`, by design (`poc/lab/load/README.md`); it is counted as a non-2xx response by k6.
- `0` means that no HTTP response was received for the request (k6 status 0).
- `500` appears only at 100,000 rows. The app's log shows PHP stopping a request at its 30-second execution limit inside the database layer (`Illuminate\Database\Connection`). The log keeps no request path, so the route is not named by it; the counts per step (in `t-2`, one route of the eight; in `t-3` and `t-5`, one route of the first four of the route list) say that it is always a single route of the first four. The database utilization of a step includes the database time of those requests.

## The steps

### `t-4-10000` (t-4: N_0 = 10,000 rows in the eight tables, mix `even`)

Started 2026-11-10T10:19:58+09:00, ended 2026-11-10T12:16:04+09:00. 13 steps measured; stopped after step 13 (db_utilization_at_or_above_80_percent).

| Step | Decided rps | Achieved rps | Within 5% | DB utilization (%) | App CPU (%) | Dropped | HTTP statuses | Started |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | yes | 2.0 | 0.6 | 0 | 200x35, 302x5 | 10:19:58 |
| 2 | 0.07 | 0.0701 | yes | 2.4 | 0.8 | 0 | 200x35, 302x5 | 10:35:23 |
| 3 | 0.1 | 0.1000 | yes | 3.1 | 1.1 | 0 | 200x35, 302x5 | 10:46:56 |
| 4 | 0.15 | 0.1501 | yes | 4.1 | 1.7 | 0 | 200x70, 302x10 | 10:55:40 |
| 5 | 0.2 | 0.2000 | yes | 5.2 | 2.2 | 0 | 200x70, 302x10 | 11:06:38 |
| 6 | 0.3 | 0.3000 | yes | 7.3 | 3.3 | 0 | 200x105, 302x15 | 11:15:22 |
| 7 | 0.45 | 0.4494 | yes | 10.6 | 4.9 | 0 | 200x140, 302x20 | 11:24:06 |
| 8 | 0.7 | 0.6997 | yes | 15.9 | 7.5 | 0 | 200x210, 302x30 | 11:32:05 |
| 9 | 1.0 | 1.0000 | yes | 22.4 | 10.8 | 0 | 200x280, 302x40 | 11:39:52 |
| 10 | 1.5 | 1.5000 | yes | 34.1 | 16.5 | 0 | 0x2, 200x418, 302x60 | 11:47:15 |
| 11 | 2.2 | 2.1942 | yes | 50.0 | 24.3 | 0 | 0x12, 200x583, 302x83 | 11:54:37 |
| 12 | 3.3 | 3.3003 | yes | 73.8 | 36.5 | 0 | 0x1, 200x874, 302x125 | 12:01:49 |
| 13 | 5.0 | 4.9671 | yes | 96.6 | 48.3 | 0 | 0x148, 200x1210, 302x152 | 12:08:56 |

### `t-1-30000` (t-1: 3 x N_0 = 30,000 rows in the eight tables, mix `even`)

Started 2026-11-10T12:17:04+09:00, ended 2026-11-10T13:51:43+09:00. 10 steps measured; stopped after step 10 (db_utilization_at_or_above_80_percent).

| Step | Decided rps | Achieved rps | Within 5% | DB utilization (%) | App CPU (%) | Dropped | HTTP statuses | Started |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | yes | 4.2 | 1.7 | 0 | 200x35, 302x5 | 12:17:04 |
| 2 | 0.07 | 0.0701 | yes | 5.4 | 2.3 | 0 | 200x35, 302x5 | 12:32:28 |
| 3 | 0.1 | 0.1000 | yes | 7.4 | 3.2 | 0 | 200x35, 302x5 | 12:44:02 |
| 4 | 0.15 | 0.1501 | yes | 10.6 | 4.8 | 0 | 200x70, 302x10 | 12:52:46 |
| 5 | 0.2 | 0.2000 | yes | 13.8 | 6.3 | 0 | 200x70, 302x10 | 13:03:44 |
| 6 | 0.3 | 0.3000 | yes | 20.3 | 9.5 | 0 | 200x105, 302x15 | 13:12:28 |
| 7 | 0.45 | 0.4466 | yes | 30.4 | 14.4 | 0 | 200x140, 302x19 | 13:21:12 |
| 8 | 0.7 | 0.6997 | yes | 48.4 | 22.8 | 0 | 200x210, 302x30 | 13:29:10 |
| 9 | 1.0 | 1.0000 | yes | 67.0 | 32.1 | 0 | 200x280, 302x40 | 13:36:58 |
| 10 | 1.5 | 1.4844 | yes | 87.8 | 42.4 | 0 | 0x29, 200x390, 302x56 | 13:44:20 |

### `t-2-100000` (t-2: 10 x N_0 = 100,000 rows in the eight tables, mix `even`)

Started 2026-11-10T13:54:06+09:00, ended 2026-11-10T15:14:01+09:00. 8 steps measured; stopped after step 8 (db_utilization_at_or_above_80_percent).

| Step | Decided rps | Achieved rps | Within 5% | DB utilization (%) | App CPU (%) | Dropped | HTTP statuses | Started |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | yes | 9.2 | 4.3 | 0 | 200x30, 302x5, 500x5 | 13:54:06 |
| 2 | 0.07 | 0.0701 | yes | 12.5 | 5.9 | 0 | 200x30, 302x5, 500x5 | 14:09:30 |
| 3 | 0.1 | 0.1000 | yes | 17.5 | 8.4 | 0 | 200x30, 302x5, 500x5 | 14:21:04 |
| 4 | 0.15 | 0.1501 | yes | 25.8 | 12.5 | 0 | 200x60, 302x10, 500x10 | 14:29:48 |
| 5 | 0.2 | 0.1975 | yes | 34.3 | 16.7 | 0 | 200x60, 302x10, 500x9 | 14:40:46 |
| 6 | 0.3 | 0.2975 | yes | 51.2 | 25.0 | 0 | 200x90, 302x15, 500x14 | 14:49:30 |
| 7 | 0.45 | 0.4438 | yes | 75.6 | 37.6 | 0 | 0x18, 200x120, 302x20 | 14:58:15 |
| 8 | 0.7 | 0.6676 | yes | 99.0 | 50.0 | 0 | 0x62, 200x139, 302x28 | 15:06:13 |

### `t-3-100000` (t-3: 10 x N_0 = 100,000 rows in the eight tables, mix `first80`)

Started 2026-11-10T15:14:15+09:00, ended 2026-11-10T16:18:22+09:00. 6 steps measured; stopped after step 6 (db_utilization_at_or_above_80_percent).

| Step | Decided rps | Achieved rps | Within 5% | DB utilization (%) | App CPU (%) | Dropped | HTTP statuses | Started |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | yes | 14.6 | 6.8 | 0 | 200x30, 302x2, 500x8 | 15:14:15 |
| 2 | 0.07 | 0.0701 | yes | 19.6 | 9.3 | 0 | 200x30, 302x2, 500x8 | 15:29:38 |
| 3 | 0.1 | 0.1000 | yes | 27.5 | 13.3 | 0 | 200x30, 302x2, 500x8 | 15:41:12 |
| 4 | 0.15 | 0.1482 | yes | 41.1 | 19.9 | 0 | 200x60, 302x4, 500x15 | 15:49:56 |
| 5 | 0.2 | 0.1975 | yes | 54.8 | 26.8 | 0 | 200x60, 302x4, 500x15 | 16:00:54 |
| 6 | 0.3 | 0.2950 | yes | 81.2 | 40.0 | 0 | 0x22, 200x90, 302x6 | 16:09:38 |

### `t-5-100000` (t-5: 10 x N_0 = 100,000 rows in the eight tables, mix `last80`)

Started 2026-11-10T16:18:36+09:00, ended 2026-11-10T17:53:17+09:00. 10 steps measured; stopped after step 10 (db_utilization_at_or_above_80_percent).

| Step | Decided rps | Achieved rps | Within 5% | DB utilization (%) | App CPU (%) | Dropped | HTTP statuses | Started |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | yes | 4.2 | 1.7 | 0 | 200x30, 302x8, 500x2 | 16:18:36 |
| 2 | 0.07 | 0.0701 | yes | 5.5 | 2.4 | 0 | 200x30, 302x8, 500x2 | 16:34:00 |
| 3 | 0.1 | 0.1000 | yes | 7.6 | 3.4 | 0 | 200x30, 302x8, 500x2 | 16:45:34 |
| 4 | 0.15 | 0.1501 | yes | 10.8 | 5.1 | 0 | 200x60, 302x16, 500x4 | 16:54:18 |
| 5 | 0.2 | 0.2000 | yes | 14.1 | 6.7 | 0 | 200x60, 302x16, 500x4 | 17:05:16 |
| 6 | 0.3 | 0.3000 | yes | 20.8 | 10.1 | 0 | 200x90, 302x24, 500x6 | 17:14:00 |
| 7 | 0.45 | 0.4466 | yes | 30.9 | 15.1 | 0 | 200x120, 302x32, 500x7 | 17:22:44 |
| 8 | 0.7 | 0.6968 | yes | 47.8 | 23.5 | 0 | 200x180, 302x48, 500x11 | 17:30:42 |
| 9 | 1.0 | 0.9969 | yes | 67.6 | 33.7 | 0 | 0x8, 200x240, 302x64, 500x7 | 17:38:30 |
| 10 | 1.5 | 1.4937 | yes | 97.1 | 49.2 | 0 | 0x22, 200x360, 302x96 | 17:45:53 |
