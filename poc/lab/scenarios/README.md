# Calibration-only scenarios of the lab backtest

Calibrates on the lab's three calibration runs (`cal-1`, `cal-2`, `cal-3`; `docs/simulator-spec.md` section 7.2) and makes **no
prediction**. The scenario file has calibration scenarios only: with no `target` scenario `bin/simulate` has nothing to predict and
stops after the calibration (the fits, the coefficients, the residuals, the cut coefficients, the width).

| File | What it does |
| --- | --- |
| `make-calibration-scenario` (`.php`, `CalibrationScenario.php`) | The plan and the record of each run -> the scenario file (section 3.4 form). The rps of each route is the plan's, `observed` is the record's database utilization; nothing is typed by hand. A run whose rps did not come out (not `valid`) is refused. |
| `recalibrate` | Refuses a scenario file that has a `target` scenario, runs `bin/simulate`, then checks (`check-output.php`) that the calibration is in `prediction.json` and its list of predictions is empty. |

```bash
poc/lab/scenarios/make-calibration-scenario \
  --run=cal-1:<run dir> --run=cal-2:<run dir> --run=cal-3:<run dir> > calibration.yaml     # a run dir holds plan.json and record.json
poc/lab/scenarios/recalibrate --estimate=<estimate dir> --scenario=calibration.yaml --out=<output dir>
```

The tables that get the scale are `entities`, `images` and `references` at 10,000 (the lab's `N_0`; the union of the routes' N tables), and the
database has 2 cores. `--scale`, `--tables` and `--cores` change them. The estimate directory is one made by `bin/estimate
--with-formulas`; to compare two estimates, run `recalibrate` once for each with the same scenario file.

Exit codes of `recalibrate`: 0 calibrated and no prediction / 4 the scenario file has a `target` scenario (nothing run) / 3 a prediction or
no calibration in the output / the others are those of `bin/simulate`.

The tests are in `poc/runtime/tests/Unit/LabScenarios/` (suite `RuntimeUnit`).
