# Score of the lab ladders

The score of the five prediction scenarios (`docs/simulator-spec.md` sections 7.2 and 7.4 B), made by the tool of `poc/lab/score/` from the prediction file `poc/lab/predictions/ladder-prediction.json` and the records in `docs/research/lab-data/ladder/`. No rule, prediction, estimate, calibration, stage or scenario was changed to make it.

| File | What it is |
| --- | --- |
| `score-input.json` | The input of `LadderScore::score()`: the prediction and the measured stages side by side. Made by `LadderInput::join()` from the prediction file and the five `ladder.json` read by `LadderMeasurements::fromLadders()` (`make-input.php` is the whole connector). It adds no number |
| `score.json`, `score.md` | The output of `poc/lab/score/score-ladder --input=score-input.json` |
| `http-status.md` | The HTTP statuses (500 and status 0 among them) of every step, for reference. Not read by the score |

`score-ladder --prediction=<prediction file> --ladder=<ladder.json> ...` (one `--ladder` per scenario) reads the same files and writes `score.json` and `score.md` that are byte-identical to the two here.

## Order of the prediction and the measurements

- The prediction file was committed in `5bdf6e88254439dd6127b39a503cf046384bac91` (2026-11-10T10:18:07+09:00).
- The first step of any scenario started at 2026-11-10T10:19:58+09:00 (`t-4`), after it. All 47 step records name that commit.
- From that commit to the commit of the measurements, the only file that changed outside `docs/research/lab-data/ladder/` is `docs/decisions.md`. The prediction file, the estimates, the calibration, the stages, the scenarios and the scoring tool are unchanged.
