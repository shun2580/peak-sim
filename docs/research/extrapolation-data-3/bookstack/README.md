# Test A at 300,000 rows: BookStack

Tool: `git archive` of `8f4cd65` (it carries the build limit of 7,200 s, D200). Same steps as the verification app, with `--data=generator --profile=bookstack`
and the 31 listed items. The 12 routes of D105 were captured in a separate run (sizes 10, 100, 1,000, 10,000 and 30,000, D199 (10)) and merged into the
capture and the EXPLAIN of the main run with `../../extrapolation-data-2/merge_inputs.py` (each of the 12 whole from the 12-route run). The merged fits are those of the second test,
as they are. `bin/estimate` was run twice and compared byte for byte.

`estimate.json` has 36 routes; the EXPLAIN has 35 (`GET /oidc/callback` has none). Marked routes: 22 of 35.

| File | SHA-256 |
| --- | --- |
| `capture.json` of the main run (not in the repository) | `be9439f7da5ca10c624b4aa9aab3b43b255651d0cf4580f0c91c468fdadf586b` |
| `capture.json` of the 12 routes (not in the repository) | `41c9de0147be6db3bfeb969ee58113748df5b501bfa34c914058920da1dd646e` |
| `explain.json` of the main run (not in the repository) | `3fc4a5e79cb3c11823fe7ca1e0411c3dc78aa98f5c022968b2b16dfa71d6d193` |
| `explain.json` of the 12 routes (not in the repository) | `68f066b5f06f73fe6e28a2c4c272d2bbe471f728682824ec318a04eb7c244f9d` |
| merged `capture.json` (not in the repository) | `8e2830c3733ec4049e9a71b737258da70e6b7fda0bf6938ae2bd97075690fe8c` |
| merged `explain.json` (not in the repository) | `83218b2ca9a64dd0c49213ed6acd699181ebdd9b42085aec3c5f5ba35c4d9b5a` |
| `fits.json` | `5f409c300b490463b9a198e677f301e09125d288a0d06d89fbce19c01a3a518f` |
| `predictions.json` | `5753567fbad54c210aa63a87390644e5f8a97b6ecc9d2a9399a5cc16f12882f1` |
| `estimate.json` = `poc/lab/predictions/extrapolation-3-bookstack-estimate.json` | `9162945b83ceaa752316054ca3f33d416389ae9d92c944c8d63ac9878414a13a` |
| `marked-routes.json` = `poc/lab/predictions/extrapolation-3-bookstack-marked.json` | `51ac4bf056a7f1387fffef5607fa4ee6a584bf40354387d38b57add60cf947de` |
