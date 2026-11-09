# Route list of the lab backtest

The eight routes the lab backtest loads (`docs/simulator-spec.md` section 7.2, "ルートの選び方"). The list is made by a script, by the rule
of that section, and by nothing else: nothing is measured, nothing is added or dropped by hand.

| File | What it is |
| --- | --- |
| `make-route-list` | The command. Uses PHP on this machine, or a throwaway container of the dev image with no network. |
| `make-route-list.php`, `RouteList.php` | The script and the rule it applies. |
| `route-list.json` | The list: the eight selected routes (rank, static lower bound, first or second half) and all candidates. |
| `SHA256SUMS` | SHA-256 of the two input files, the script and the list. Check with `sha256sum -c` from the repository root. |

The rule: the GET routes whose cost the estimate (`estimate.json`, section 4) could work out are ordered by the degree of the static lower
bound (`fits.json`), largest first (`O(N*M)` = 2, `O(N)` = 1, `O(1)` and no bound = 0), and by the string "METHOD URI" in byte order when the
degree is the same. The first eight are taken. Ranks 1-4 are the first half and ranks 5-8 the second half; `cal-2` and `cal-3` mix the
routes by these halves.

Make the list again (the output must not change):

```bash
poc/lab/routes/make-route-list | cmp - poc/lab/routes/route-list.json
```

The tests are in `poc/runtime/tests/Unit/LabRoutes/` (suite `RuntimeUnit`).
