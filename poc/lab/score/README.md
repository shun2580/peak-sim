# Scoring the lab backtest

Scores the ladders of the lab backtest (`docs/simulator-spec.md` sections 7.2 and 7.4 B): which stages count, the four items at
the minimum and at the target, the measured limit rps of each scenario, a table per scenario, and every stage where the safe-side
prediction fell below the observation. It copies the rules of the spec and moves none of them. The tool is built and committed
before the first stage of a prediction scenario is measured, so that no rule can have been bent after seeing a result.

| File | What it is |
| --- | --- |
| `LadderScore.php` | The rules, as one pure function: `LadderScore::score(array $input): array`. No file, no clock, no randomness |
| `LadderScoreText.php` | The result as Markdown |
| `LadderInput.php` | Puts the prediction file and the measured stages side by side in the form `LadderScore` reads. Adds no number; refuses what does not fit |
| `score-ladder` (`.php`) | The command: input in, `score.json` and `score.md` out |

The tests are in `poc/runtime/tests/Unit/LabScore/` (suite `RuntimeUnit`).

## The rules, and how each one was read

| Rule | In the code |
| --- | --- |
| Usable stage: valid, observed utilization from 10% to 90% | both ends in (to 1e-9) |
| Item 1: mean abs. difference (point) below the naive estimate's | strictly below; a tie is not smaller |
| Item 2: mean abs. difference, 15 points (target 5) | the end is in |
| Item 3: "exceeded the threshold" agrees, over all valid stages, 80% or more | exceeded means strictly above the threshold (as `LimitSearch::exceeds`); 80% is held as 8 in 10, in whole numbers |
| Item 4: predicted limit rps (point) within 20% (target 10%) of the measured one, over the scenarios whose limit was measured, 80% or more | the end is in; a predicted limit of `null` (no load) is a miss |
| Measured limit rps | a line between the two valid stages that straddle the threshold (the first valid stage that reaches it, and the valid stage just below it), `interpolated`; when a stage at or above the threshold was measured but was invalid, a line from the two valid stages with the largest rps, `extended`; fewer than two valid stages, `not_measured` (`fewer_than_two_valid`). Any other case is `not_measured` too, with a reason: `app_bound_below_threshold` (the ladder stopped below the threshold before the top stage), `never_reached_threshold` (the top stage was measured and no valid stage reached the threshold), `no_valid_stage_below_threshold` |
| Indeterminate | fewer than 10 usable stages, or fewer than 4 scenarios whose limit was measured. Never counted as a pass; the items are still shown |

Where the spec is silent, the code does the following, and says so in the output:

- Every valid stage is at or above the threshold and none was invalid at or above it (nothing straddles it, and the spec does not extend):
  `not_measured` (`no_valid_stage_below_threshold`).
- A line that does not rise (or that reaches the threshold at an rps of 0 or less) never reaches it: `not_measured`, with the reason.
- Reaching the threshold (to measure a limit) is "at or above"; exceeding it (item 3) is "strictly above".

## The input of `LadderScore::score()`

Utilizations are fractions (0.21 is 21%). Each stage has the predicted point, safe side and naive value, and `measured`: `null` when the
stage was not measured, otherwise `{valid, utilization}`. `valid` is whether the decided rps came out (within 5%). Each scenario has the
predicted limit rps (point, safe, naive; `null` for none).

```json
{"threshold": 0.8,
 "scenarios": [{"id": "t-1",
                "stages": [{"rps": 0.05,
                            "predicted": {"point": 0.10, "safe": 0.12, "naive": 0.20},
                            "measured": {"valid": true, "utilization": 0.11}}],
                "predicted_limit_rps": {"point": 1.2, "safe": 1.0, "naive": 2.0}}]}
```

## Run

```bash
poc/lab/score/score-ladder --input=<scoring input .json> --out=<output dir>   # writes score.json and score.md
```

The command uses the PHP on the machine if there is one, otherwise a throwaway container of the dev image (no network, the input
read-only). Exit codes: 0 scored (a fail or an indeterminate is still 0; read the verdict) / 2 wrong usage or an unusable input /
1 an output cannot be written / 11 no PHP and no image.
