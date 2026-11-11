# Calibration-only scenarios of the lab backtest

Calibrates on the lab's calibration runs (`cal-1`, `cal-2`, `cal-3` at `N_0`, and `cal-4` at 0.3 x `N_0`; `docs/simulator-spec.md`
section 7.2) and makes **no prediction**. The scenario file has calibration scenarios only: with no `target` scenario `bin/simulate` has nothing to predict and
stops after the calibration (the fits, the coefficients, the residuals, the cut coefficients, the width).

| File | What it does |
| --- | --- |
| `make-calibration-scenario` (`.php`, `CalibrationScenario.php`) | The plan and the record of each run -> the scenario file (section 3.4 form). The rps of each route is the plan's, `observed` is the record's database utilization; nothing is typed by hand. Each scenario has its own `tables`, so a run can be at its own count of rows (`--run-scale=<id>:<n>`). A run whose rps did not come out (not `valid`) is refused, and so is `cal-3-orig` (a record only, never a calibration scenario). |
| `calibration-gate` (`.php`) | **Run before a calibration is measured**, from the plans, the counts of rows and the estimate alone (`bin/measure-calibration` calls it, and measures nothing if it refuses): the degree to which the set of planned runs can tell the two coefficients apart, for the whole set and for every set that leaves one run out. Exit 14 when the smallest is below 0.5. See `poc/lab/README.md`. It changes nothing in what `recalibrate` and `bin/simulate` write. |
| `recalibrate` | Refuses a scenario file that has a `target` scenario, runs `bin/simulate`, then checks (`check-output.php`) that the calibration is in `prediction.json` and its list of predictions is empty. |

```bash
poc/lab/scenarios/make-calibration-scenario \
  --run=cal-1:<run dir> --run=cal-2:<run dir> --run=cal-3:<run dir> --run=cal-4:<run dir> --run-scale=cal-4:3000 > calibration.yaml
# a run dir holds plan.json and record.json; with the first three runs only (no cal-4) the file is the one committed under
# docs/research/lab-data/calibration/fit/, byte for byte (a test holds that)
poc/lab/scenarios/recalibrate --estimate=<estimate dir> --scenario=calibration.yaml --out=<output dir>
```

The tables that get the scale are `entities`, `images` and `references` at 10,000 (the lab's `N_0`; the union of the routes' N tables) for
every run without a `--run-scale` of its own, and the database has 2 cores. `--scale`, `--run-scale`, `--tables` and `--cores` change them.
With four runs the width of the calibration is the four fits that leave one run out (beside the fit on all four). The estimate directory is one made by `bin/estimate
--with-formulas`; to compare two estimates, run `recalibrate` once for each with the same scenario file.

Exit codes of `recalibrate`: 0 calibrated and no prediction / 4 the scenario file has a `target` scenario (nothing run) / 3 a prediction or
no calibration in the output / the others are those of `bin/simulate`.

The tests are in `poc/runtime/tests/Unit/LabScenarios/` (suite `RuntimeUnit`).
