# Verdict outputs of test A at 240,000 rows

What the verdict script of `docs/research/lab-data/dryrun-286/judge/` (`judge_a.py`, `order_check.py`, committed in `53825c8` and `62ba6a2`, not changed) wrote when it was run on the records `../measured-240000/` (committed in `0c8fc47`) and the four prediction files `poc/lab/predictions/extrapolation-4-{fixtures,bookstack}-{estimate,marked}.json` (committed in `f52f692`). The script's own check (`selftest.sh`) passed 32 of 32 before it was used. The verdict and its reading are in `docs/research/extrapolation-test-4.md`.

| File | What it is |
| --- | --- |
| `a-verdict.json`, `a-verdict.md` | per application the verdict (rule B decides, rule A shown) and its reasons, the four numbers of condition 1a, and the routes outside the goal |
| `compare.json`, `compare.md` | the output of the comparison tool `docs/research/extrapolation-data-2/compare_test2.py` (byte for byte the file the third run used), unchanged; this is the `R1` output of the script |
| `order.md` | the order check: the four prediction files were committed before the first measurement and are unchanged since |

The five files are byte copies of what the script wrote. Their SHA-256 are in section 4.1 of `../../extrapolation-test-4.md`, written before they were added here, and match.

What was read: `../measured-240000/fixtures/attempts.jsonl`; `../measured-240000/bookstack/attempts.jsonl` (the second cold round only); `../measured-240000/variance/bookstack/` (the repeat). The verification app has no marked route, so its repeat was given as an empty directory. `../measured-240000/_interrupted_1112/` (the cut first BookStack round) was not read.

Commands (from the repository root; `<out>` is a directory, `<empty>` an empty directory, `<measured>` is `docs/research/extrapolation-data-4/measured-240000`, `<p>` is `poc/lab/predictions`):

```bash
python3 -I docs/research/lab-data/dryrun-286/judge/judge_a.py --out <out> \
  --app fixtures <p>/extrapolation-4-fixtures-estimate.json <p>/extrapolation-4-fixtures-marked.json <measured>/fixtures/attempts.jsonl <empty> \
  --app bookstack <p>/extrapolation-4-bookstack-estimate.json <p>/extrapolation-4-bookstack-marked.json <measured>/bookstack/attempts.jsonl <measured>/variance/bookstack
python3 -I docs/research/lab-data/dryrun-286/judge/order_check.py --out <out>/order.md \
  --prediction <p>/extrapolation-4-fixtures-estimate.json --prediction <p>/extrapolation-4-fixtures-marked.json \
  --prediction <p>/extrapolation-4-bookstack-estimate.json --prediction <p>/extrapolation-4-bookstack-marked.json \
  --order-record <measured>/order_record.txt
```

The records read have not changed since `0c8fc47`. The script directory has changed once since `62ba6a2`, in `estimate_length.py`, which `judge_a.py` and `order_check.py` do not read.
