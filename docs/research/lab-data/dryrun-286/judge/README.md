# Verdict scripts for the fifth round (B and A)

These scripts make the verdict of B (the lab backtest) and of A (the extrapolation test) from the raw records, and the table of conditions 1 to 3 of section 0 of the spec. They were written and checked **before** any measurement of the fifth round, so that no rule can be bent after a result is seen.

**The scripts copy no rule and change none.** The rules of B (spec 7.2 and 7.4 B) are in `poc/lab/score/` (committed before the fourth round's first step); the rules of A (spec 7.1 and 7.4 A, rule B, D212) are in `docs/research/extrapolation-data-2/compare_test2.py`, which `judge_a.py` refuses to run unless it is byte for byte the file the third run used (SHA-256 in the script). What the scripts add is the order in which the figures are put, the numbers condition 1 to 3 are counted with (D153, D167, D215, spec 7.4.4), and checks that the two tools and the script agree. Everything here reads files only: no database, no app, no clock, and the lab is never touched.

| File | What it does |
| --- | --- |
| `judge_b.py` | Runs `poc/lab/score/score-ladder` on a prediction file and a directory of `ladder.json` (one per scenario). Writes items 1 to 4 at the lowest standard and at the goal, the conditions of "undecidable" with their numbers (usable steps below 10, scenarios whose limit rps was measured below 4), the limit rps of each scenario, the numbers condition 1b is counted with, and whether 1b counts under spec 7.4.4 (the goal met, 5 times the capture's largest size or more, a count not seen before) |
| `judge_a.py` | Runs `compare_test2.py` (rule B decides, rule A shown) on the committed estimate and marked files and the records of the measure and of the three extra cold laps. Writes per application the verdict and its reasons, the four numbers of condition 1a (per application, D215), and the routes outside the goal (condition 3), with the routes not measured counted apart |
| `conditions.py` | Puts the two verdict files together as the table of conditions 1a, 1b, 2 and 3. Adds no figure. Condition 2 is "not measured", never 0 |
| `order_check.py` | The prediction was committed before the first measurement and is unchanged since: reads `git log`, the step records' `started_at` and the commit each step recorded, or an order record |
| `estimate_length.py` | The estimate of how long the measurements will take, from the windows and waits of the rules and the times of the earlier records. Writes a Markdown file; every premise is written as a premise |
| `make_fake.py` | Writes made-up records in the shape a dry run writes (B with 2 steps per scenario and with full ladders; A with a few routes of each application, one route that fails, one that measures 0). They are fake and used for nothing else |
| `selftest.sh` | 31 checks: known answers (the 90,000-row B, the first round's B, the third run's A must give the committed figures; the tool's own `score.json` and `compare.json` must be byte for byte the committed ones), the made-up records, the order check (including a case that must fail), and a changed comparison tool (must stop the script) |

## Use

```bash
# B: <ladders> holds one directory per scenario, each with ladder.json
python3 -I judge_b.py --prediction <prediction.json> --ladder-dir <ladders> --out <dir> --captured-max 30000 --seen <seen-counts.txt>
# A: one --app per application
python3 -I judge_a.py --out <dir> --app fixtures <estimate> <marked> <measure> <variance> --app bookstack <estimate> <marked> <measure> <variance>
python3 -I conditions.py --out <dir> --a <dir>/a-verdict.json --b <dir>/b-verdict.json
python3 -I order_check.py --out <file> --prediction <file> [--prediction ...] --ladder-dir <ladders> --order-record <file>
bash selftest.sh        # before anything else
```

- `--seen` is a list of the row counts already seen, one per line. It is an input the person who judges writes from the check of D223 and the earlier records; the script does not guess it. Without it condition 1b is never counted.
- "Undecidable" is the spec's word. The comparison tool says "indeterminate"; `judge_a.py` writes undecidable.
- The verdict of B is the tool's. The script re-derives only the reasons for "undecidable" and stops (`tool_and_script_agree_on_undecidable` false) if it disagrees with the tool.

## What the checks found while the scripts were written

Each was a defect of a script, found by the made-up records or the known answers, and corrected before use: a line of the records written with sorted keys was not read by the comparison tool (it reads a line from its first `{"route"`); the word "indeterminate" was not mapped to undecidable, so an undecidable application was printed as met; a relative error of `null` (a route that measures 0) stopped the writing of the table; two percent signs in a text template. No rule of a tool was touched.

## What is not covered

- The second reading of T6 (two readers rebuilding the counts from the raw records, each without seeing the other's work) is not these scripts. These scripts are one reading; the figures in the step records (`db_utilization`) are used as written.
- Whether a record is complete (the limits of each step written, the stop mark kept, the containers recreated) is checked by the person who measures; `judge_b.py` checks only what the verdict reads.
