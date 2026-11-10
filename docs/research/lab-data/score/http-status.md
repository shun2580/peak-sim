# HTTP statuses of the measured steps (for reference)

Counts from the `record.json` of each step (`http_status`), next to the score in `score.md`. They are **not** part of any rule of the score: the spec (sections 7.2 and 7.4 B) has no rule on HTTP statuses, `LadderMeasurements` reads only `decided_rps`, `rps_valid` and `db_utilization` of a step, and `LadderScore` has no field for a status. `302` is the by-design answer of the OIDC callback; `500` is a request stopped by PHP at its 30-second execution limit (100,000 rows only); `0` is a request with no HTTP response. The requests with status 500 and 0 are counted in the achieved rps (`achieved_rps` = `requests_in_window` / `window_sec` in all 47 records), and their database time is in the utilization.

## Scenario t-1

| Step | Decided rps | Achieved rps | DB utilization (%) | 500 | status 0 | All statuses |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | 4.2 | 0 | 0 | 200x35, 302x5 |
| 2 | 0.07 | 0.0701 | 5.4 | 0 | 0 | 200x35, 302x5 |
| 3 | 0.1 | 0.1000 | 7.4 | 0 | 0 | 200x35, 302x5 |
| 4 | 0.15 | 0.1501 | 10.6 | 0 | 0 | 200x70, 302x10 |
| 5 | 0.2 | 0.2000 | 13.8 | 0 | 0 | 200x70, 302x10 |
| 6 | 0.3 | 0.3000 | 20.3 | 0 | 0 | 200x105, 302x15 |
| 7 | 0.45 | 0.4466 | 30.4 | 0 | 0 | 200x140, 302x19 |
| 8 | 0.7 | 0.6997 | 48.4 | 0 | 0 | 200x210, 302x30 |
| 9 | 1.0 | 1.0000 | 67.0 | 0 | 0 | 200x280, 302x40 |
| 10 | 1.5 | 1.4844 | 87.8 | 0 | 29 | 0x29, 200x390, 302x56 |

## Scenario t-2

| Step | Decided rps | Achieved rps | DB utilization (%) | 500 | status 0 | All statuses |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | 9.2 | 5 | 0 | 200x30, 302x5, 500x5 |
| 2 | 0.07 | 0.0701 | 12.5 | 5 | 0 | 200x30, 302x5, 500x5 |
| 3 | 0.1 | 0.1000 | 17.5 | 5 | 0 | 200x30, 302x5, 500x5 |
| 4 | 0.15 | 0.1501 | 25.8 | 10 | 0 | 200x60, 302x10, 500x10 |
| 5 | 0.2 | 0.1975 | 34.3 | 9 | 0 | 200x60, 302x10, 500x9 |
| 6 | 0.3 | 0.2975 | 51.2 | 14 | 0 | 200x90, 302x15, 500x14 |
| 7 | 0.45 | 0.4438 | 75.6 | 0 | 18 | 0x18, 200x120, 302x20 |
| 8 | 0.7 | 0.6676 | 99.0 | 0 | 62 | 0x62, 200x139, 302x28 |

## Scenario t-3

| Step | Decided rps | Achieved rps | DB utilization (%) | 500 | status 0 | All statuses |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | 14.6 | 8 | 0 | 200x30, 302x2, 500x8 |
| 2 | 0.07 | 0.0701 | 19.6 | 8 | 0 | 200x30, 302x2, 500x8 |
| 3 | 0.1 | 0.1000 | 27.5 | 8 | 0 | 200x30, 302x2, 500x8 |
| 4 | 0.15 | 0.1482 | 41.1 | 15 | 0 | 200x60, 302x4, 500x15 |
| 5 | 0.2 | 0.1975 | 54.8 | 15 | 0 | 200x60, 302x4, 500x15 |
| 6 | 0.3 | 0.2950 | 81.2 | 0 | 22 | 0x22, 200x90, 302x6 |

## Scenario t-4

| Step | Decided rps | Achieved rps | DB utilization (%) | 500 | status 0 | All statuses |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | 2.0 | 0 | 0 | 200x35, 302x5 |
| 2 | 0.07 | 0.0701 | 2.4 | 0 | 0 | 200x35, 302x5 |
| 3 | 0.1 | 0.1000 | 3.1 | 0 | 0 | 200x35, 302x5 |
| 4 | 0.15 | 0.1501 | 4.1 | 0 | 0 | 200x70, 302x10 |
| 5 | 0.2 | 0.2000 | 5.2 | 0 | 0 | 200x70, 302x10 |
| 6 | 0.3 | 0.3000 | 7.3 | 0 | 0 | 200x105, 302x15 |
| 7 | 0.45 | 0.4494 | 10.6 | 0 | 0 | 200x140, 302x20 |
| 8 | 0.7 | 0.6997 | 15.9 | 0 | 0 | 200x210, 302x30 |
| 9 | 1.0 | 1.0000 | 22.4 | 0 | 0 | 200x280, 302x40 |
| 10 | 1.5 | 1.5000 | 34.1 | 0 | 2 | 0x2, 200x418, 302x60 |
| 11 | 2.2 | 2.1942 | 50.0 | 0 | 12 | 0x12, 200x583, 302x83 |
| 12 | 3.3 | 3.3003 | 73.8 | 0 | 1 | 0x1, 200x874, 302x125 |
| 13 | 5.0 | 4.9671 | 96.6 | 0 | 148 | 0x148, 200x1210, 302x152 |

## Scenario t-5

| Step | Decided rps | Achieved rps | DB utilization (%) | 500 | status 0 | All statuses |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 0.05 | 0.0500 | 4.2 | 2 | 0 | 200x30, 302x8, 500x2 |
| 2 | 0.07 | 0.0701 | 5.5 | 2 | 0 | 200x30, 302x8, 500x2 |
| 3 | 0.1 | 0.1000 | 7.6 | 2 | 0 | 200x30, 302x8, 500x2 |
| 4 | 0.15 | 0.1501 | 10.8 | 4 | 0 | 200x60, 302x16, 500x4 |
| 5 | 0.2 | 0.2000 | 14.1 | 4 | 0 | 200x60, 302x16, 500x4 |
| 6 | 0.3 | 0.3000 | 20.8 | 6 | 0 | 200x90, 302x24, 500x6 |
| 7 | 0.45 | 0.4466 | 30.9 | 7 | 0 | 200x120, 302x32, 500x7 |
| 8 | 0.7 | 0.6968 | 47.8 | 11 | 0 | 200x180, 302x48, 500x11 |
| 9 | 1.0 | 0.9969 | 67.6 | 7 | 8 | 0x8, 200x240, 302x64, 500x7 |
| 10 | 1.5 | 1.4937 | 97.1 | 0 | 22 | 0x22, 200x360, 302x96 |
