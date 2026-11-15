# The first prediction of the third lab backtest, before s-4 and s-5 were redrawn

This is a **record only**. It is not the prediction the third lab backtest is scored against, and nothing here is used to measure or to score.
The prediction that stands is `poc/lab/predictions/ladder-prediction-3.json` (see D209).

By the ruling of 2026-11-16 07:45 (D206), s-4 and s-5 were redrawn (D207), so every rung was replaced **before it was measured**: no request had been
sent to the 300,000-row database when the first prediction was replaced, and no rule, threshold, estimate or calibration was changed after any value of
it was seen. The first version of s-4 put 80% of the requests on the odd places and s-5 on the even places; the two heavy routes (the ZIP export and the
book page) both sit on even places, so s-5 had the same heavy-route shares as s-2 and s-4 the same as s-3 (only three distinct shapes among five).

It is kept, not deleted, so that the replacement can be checked: s-1, s-2 and s-3 are identical in both versions (as JSON), and only s-4, s-5 and the
hash of the input differ (D209).

| File | SHA-256 |
| --- | --- |
| `ladder-prediction.json` | `233a9e751a21bbead103cb04c29cec968f98eca27ad18d5c6fad7ab69dde84ce` |
| `ladder-prediction-3-input.yaml` | `06d7ed227aa49de75b55e1dc67379d41ffc04e1e644a895e354d925a3342fb34` |

It is not under `poc/lab/predictions/` on purpose: that directory is the one the prediction gate reads, and a file here must never open the gate.
