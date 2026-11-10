# Allocation of the ladder miss

Records for the allocation of the difference between the predicted and the measured utilisation of the lab ladder (the one committed prediction, `poc/lab/predictions/`). Nothing here is a prediction, and nothing here changes the prediction, the estimate, the calibration, the formula or the score of the backtest.

## Sensitivity of the prediction to the coefficient `c_row`

The calibration on four runs has a `c_row` that is 8.2 times wider across the five fits (all four runs, and each run left out in turn) than `c_query` is (1.64 times). This puts `c_row` at the ends of that range and reads how far the committed prediction moves. The values are a **sensitivity**: they are not predictions (the backtest has one).

Coefficient sets (read from the committed prediction file, none typed by hand):

| set | `c_query` | `c_row` | meaning |
|---|---|---|---|
| `main` | 2.81705e-04 | 1.95063e-06 | main (fit on all four runs) |
| `i_min` | 2.87058e-04 | 1.37851e-06 | c_row smallest, c_query as in the same fit (without:cal-3) |
| `i_max` | 1.74879e-04 | 1.12854e-05 | c_row largest, c_query as in the same fit (without:cal-4) |
| `ii_min` | 2.81705e-04 | 1.37851e-06 | c_query of the main fit, c_row smallest |
| `ii_max` | 2.81705e-04 | 1.12854e-05 | c_query of the main fit, c_row largest |

`i_min`/`i_max` keep `c_query` as it is in the same leave-one-out fit; `ii_min`/`ii_max` keep `c_query` at the value of the main fit and move `c_row` alone.

Reading order: (i) is the primary reading, because each leave-one-out fit is the calibration's own width (simulator-spec section 6.3) and fits three of the four calibration runs; (ii) is set beside it as the literal answer to "put `c_row` alone at the ends", and it makes a pair that does not fit the calibration record. The (i) values are the ones already in the committed prediction's calibration range. This order was fixed before the per-route measurements were read.

### Limit rps (total over all routes) and its distance from the measured limit

| scenario | rows | measured | main | `i_min` | `i_max` | `ii_min` | `ii_max` |
|---|---|---|---|---|---|---|---|
| `t-1` | 30,000 | 1.3128 | 1.1157 (-15.0%) | 1.1344 (-13.6%) | 0.9235 (-29.7%) | 1.1541 (-12.1%) | 0.7225 (-45.0%) |
| `t-2` | 100,000 | 0.4966 | 0.3056 (-38.5%) | 0.3187 (-35.8%) | 0.1893 (-61.9%) | 0.3239 (-34.8%) | 0.1591 (-68.0%) |
| `t-3` | 100,000 | 0.2955 | 0.1917 (-35.1%) | 0.1997 (-32.4%) | 0.1198 (-59.5%) | 0.2030 (-31.3%) | 0.1005 (-66.0%) |
| `t-4` | 10,000 | 3.7646 | 3.4713 (-7.8%) | 3.4875 (-7.4%) | 3.4291 (-8.9%) | 3.5502 (-5.7%) | 2.5472 (-32.3%) |
| `t-5` | 100,000 | 1.2100 | 0.7529 (-37.8%) | 0.7880 (-34.9%) | 0.4505 (-62.8%) | 0.8007 (-33.8%) | 0.3815 (-68.5%) |

The percentage is the limit rps of the set against the measured limit rps (the same reading as the score: predicted ÷ measured − 1).

### Move against the main fit

| scenario | `i_min` | `i_max` | `ii_min` | `ii_max` |
|---|---|---|---|---|
| `t-1` | +1.7% | -17.2% | +3.4% | -35.2% |
| `t-2` | +4.3% | -38.1% | +6.0% | -47.9% |
| `t-3` | +4.2% | -37.5% | +5.9% | -47.6% |
| `t-4` | +0.5% | -1.2% | +2.3% | -26.6% |
| `t-5` | +4.7% | -40.2% | +6.3% | -49.3% |

### Utilisation (%) at two stages, point reading

Stage 0.20 rps total:

| scenario | main | `i_min` | `i_max` | `ii_min` | `ii_max` |
|---|---|---|---|---|---|
| `t-1` | 14.3 | 14.1 | 17.3 | 13.9 | 22.1 |
| `t-2` | 52.4 | 50.2 | 84.5 | 49.4 | 100.6 |
| `t-3` | 83.5 | 80.1 | 133.5 | 78.8 | 159.2 |
| `t-4` | 4.6 | 4.6 | 4.7 | 4.5 | 6.3 |
| `t-5` | 21.3 | 20.3 | 35.5 | 20.0 | 41.9 |

Stage 0.70 rps total:

| scenario | main | `i_min` | `i_max` | `ii_min` | `ii_max` |
|---|---|---|---|---|---|
| `t-1` | 50.2 | 49.4 | 60.6 | 48.5 | 77.5 |
| `t-2` | 183.3 | 175.7 | 295.8 | 172.9 | 351.9 |
| `t-3` | 292.1 | 280.4 | 467.3 | 275.9 | 557.1 |
| `t-4` | 16.1 | 16.1 | 16.3 | 15.8 | 22.0 |
| `t-5` | 74.4 | 71.1 | 124.3 | 69.9 | 146.8 |

All 13 stages and every set (also the five fits) are in `c-row-sensitivity.json`, with the rates the model turns into a load (queries per second and rows per second) at each stage.

### Reading

- The 100,000-row scenarios (`t-2`, `t-3`, `t-5`) over-predict the utilisation, so the predicted limit is below the measured one. To reach the measured limit the predicted limit would have to rise by 63% (`t-2`), 54% (`t-3`) and 61% (`t-5`).
- Putting `c_row` at its smallest value raises the predicted limit of those three by at most 6.0%, 5.9% and 6.3% (`ii_min`; with `c_query` as in its own fit, `i_min`, by less). The smallest `c_row` in the range closes only a small part of the gap, and the largest `c_row` widens it.
- So within the range of the four-run calibration, the width of `c_row` alone does not explain the 100,000-row miss. What the sensitivity cannot say is whether the rates the model reads (queries and rows per second from the estimate) are the part that is off; that is read from the per-route measurements, not from this table.
- For `t-4` (10,000 rows) and `t-1` (30,000 rows), the smallest `c_row` moves the limit by +2.3% and +3.4% (`ii_min`); the largest moves it by -26.6% and -35.2% (`ii_max`). These two scenarios were close to the measured limit with the main fit already.

### How it was made

`c-row-sensitivity.php` is a thin bridge outside the prediction tool. It loads the estimate and the scenario file as `poc/lab/bin/predict-ladder` does (the first stage of `SimulateCommand`, the safe-side estimates at their counts), takes the two rates the model turns into a load with `UtilizationModel::rates` (point reading) at every stage, and puts a pair of coefficients on them (`utilisation = (c_query × queries + c_row × rows) ÷ cores`, the form the prediction itself uses for its calibration range). The limit rps is `LadderPrediction::limitRps`. It adds no number of its own.

Self-check: for every target scenario and stage, the `main` set equals the committed point utilisation, and each leave-one-out fit equals the committed calibration range, with a maximum absolute difference of 0 (any difference above 1e-12 stops the bridge before it writes).

Run (a throwaway PHP container, no network, the repository mounted read-only):

```
docker run --rm --network none -v "$PWD":/app:ro -v "$OUT":/out -w /app <php image> php \
  docs/research/lab-data/diagnosis/allocation/c-row-sensitivity.php poc/runtime/bootstrap.php \
  --estimate=docs/research/lab-data/estimate \
  --safe-estimate=docs/research/lab-data/estimate-10k --safe-estimate=docs/research/lab-data/estimate-30k \
  --scenario=poc/lab/predictions/ladder-prediction-input.yaml --prediction=poc/lab/predictions/ladder-prediction.json \
  --score=docs/research/lab-data/score/score.json --out=/out
```

The output `sensitivity.json` of that run is kept here as `c-row-sensitivity.json`.
