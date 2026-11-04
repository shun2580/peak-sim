# Real-app benchmark: fixed answers and a scoring tool

Not part of the official tool. It is not in the phar, not in the composer autoload, and not in the `peak-sim` command.

It answers one question that was until now settled by a person looking at each result: **how often is peak-sim right on
real apps, and did a change make that better or worse?** `truth.json` holds the answers, decided by reading the code of
the apps. `score.php` compares peak-sim's output with them and counts.

## Files

| File | What it is |
| --- | --- |
| `truth.json` | The fixed answers: per route, how the number of queries (and the memory) of one request really grows. |
| `score.php` | The command line. Reads `truth.json` and one or more outputs, prints the counts. |
| `lib/Truth.php` | Loads and checks `truth.json`; the one place where a growth word becomes a degree. |
| `lib/StaticScorer.php` | Scores the static analysis (`peak-sim analyze --format=json`). |
| `lib/RuntimeScorer.php` | Scores a runtime measurement (`routes.jsonl` and `attempts.jsonl` of a run) into the classes A to E. |
| `tests/Unit/Benchmarks/` | Tests of the above (they run with the ordinary suite; they need no app and no Docker beyond the usual PHP). |

PHP 8.2 or later, no packages. It runs where the rest of the project runs (Docker, see `docker/README.md`).

## The answers (`truth.json`)

The apps are cloned read-only outside this repository. **Their code is not copied here; an answer points at it by
`file:line`.** Each app is pinned to the commit the answers were decided on (`apps.<name>.commit`).

```
routes[]
  id, app, method, uri       the route; id is "<app> <METHOD> <uri>"
  sets[]                     which collection(s) the answer came from (below)
  axes.query / axes.memory   the answer for that axis; an axis that was not judged is absent
      growth                 "O(1)", "O(N)", "O(N*M)", "conditional", or null (not decided)
      confidence             "high" | "medium" | "low"
      axis                   in words, what grows (a table, or the children of one record)
      at_least               true when the answer is only a floor ("at least O(N)")
      decided                false when the reading did not settle it
      condition, growth_if_condition_degree
                             for "conditional": what must hold, and the degree (0/1/2) when it does
      note
  deviation_types[]          how the static lower bound is known to go wrong on this route (see below)
  evidence[]                 file, line, note: where in the app the answer comes from
  recorded_lower_bound_verdicts[]
                             what the first manual check said about the lower bound then (information only)
  earlier_records[], cross_check
                             an earlier or independent reading of the same route (information only)
```

"Grows" means: with the table named in `axis` growing, how the count for one request changes. A route that reads the
children of one record counts them as N (that is how peak-sim defines N). A growth that is bounded by a limit, or by
the size of the request itself, is not N.

**Collections (`sets`)**

| Set | Routes | Where the answer comes from |
| --- | ---: | --- |
| `settled` | 5 | The only routes peak-sim gave a firm answer on (O(1), high) in the first trial: 2 in Firefly III, 3 in Koel. Checked by reading the code and by running one request with the data growing. |
| `lower-bound-sample` | 15 | Routes that had only a lower bound, picked by an even spacing (not random) from 4 apps in the first trial. 18 were picked; 2 of them are answered more deeply in `round1-target`, and 1 in `round2-new24` (see `docs/research/real-app-scoring.md`). |
| `round1-target` | 25 | The 25 Firefly III routes of the first decisive runtime experiment, traced by reading the code before any measurement was seen. |
| `round1-control` | 2 | The two controls of that experiment. They are the same two routes as 2 of `settled`. |
| `round2-new24` | 24 | 24 further Firefly III routes: the controller routes whose static query lower bound is O(N) (39 in all) minus the 15 the first experiment used. Traced by reading the code; the answers were written down apart from any measurement of these routes. 16 are O(N), 6 are conditional (each with its `condition`), 2 are O(1). One of them, `POST /piggy-banks/remove/{piggyBank}`, is also one of the 18 sampled routes: the full trace is the answer, and the earlier record is kept in `earlier_records` and `cross_check`. |
| `round3-bookstack` | 42 | The BookStack controller routes whose static query lower bound is O(N) or O(N*M): 44 in all, 42 of them here. Traced by reading the code; the answers were written down before any measurement of these routes. 13 are O(1), 9 are O(N), 1 is O(N*M), 19 are conditional (each with its `condition`). The other 2 of the 44, `PUT /api/pages/{id}` and `PUT /books/{slug}`, were already answered in `lower-bound-sample` under the same id (an id is unique), so they are not repeated: the earlier entries stay as they were. |

The sets are small and picked by even spacing, not drawn at random. **A count here describes these routes; it does not
estimate the rate over all routes of an app.**

**`deviation_types`** (only for the routes of `round1-target` and `round2-new24`):

| Id | The static lower bound counts … |
| --- | --- |
| `conditional-event/config-gated` | a path that a default setting switches off |
| `conditional-event/unreached-branch` | an event of a branch that is not the main path (early return, redirect) |
| `conditional-event/valid-input-only` | a path taken only when the input is valid |
| `constant-loop-counted-as-nested` | a loop whose count is a constant (for example 2 legs of a double-entry record) as a nesting |
| `axis/children-of-one-bound-parent` | N or M as the children of one URL-bound record, not a table size |
| `axis/size-of-request-input` | N as the number of elements in the request body |
| `axis/other-table` | the right degree on the wrong table |
| `untraced-call-hides-the-weight` | nothing for a heavy path behind a call the analysis does not follow (an event listener) |
| `lazy-load-cached-after-first-use` | a lazy load in a loop that runs once because the relation is cached on the model |

## Scoring the static analysis

Run the analysis of each app as usual (inside that app's own container, with the app cloned at the commit in
`truth.json`), keep the JSON, then:

```bash
php benchmarks/real-apps/score.php \
  --analysis=firefly-iii=/path/to/firefly-iii/analysis.json \
  --analysis=koel=/path/to/koel/analysis.json \
  --clone=firefly-iii=/path/to/clones/firefly-iii \
  --rows
```

`--clone` checks that the clone is at the pinned commit (exit 4 otherwise; `--allow-clone-mismatch` scores anyway and
says so). `--app`, `--set` and `--axis` keep only those answers (repeat to keep several). `--format=json` gives the same
numbers as JSON. A large analysis (Snipe-IT is about 190 MB) needs a few GB of memory; the tool lifts PHP's limit.

Every answered axis of every answered route lands in exactly one category:

| Category | Meaning |
| --- | --- |
| `match` | peak-sim gave a firm growth and it equals the answer |
| `bound_tight` | peak-sim said `unknown` with a lower bound equal to the answer |
| `conditional_ok` | the answer is conditional; peak-sim gave a firm growth equal to the heavy side |
| `bound_loose` | `unknown`, and the lower bound is below the answer (true, but weaker) |
| `unknown` | `unknown` with no lower bound (nothing was claimed) |
| `bound_on_conditional` | the answer is conditional; the lower bound rests on that condition |
| `unverifiable` | the answer is only a floor and peak-sim is above it |
| **`bound_over`** | the lower bound is **above** the answer: it claims more than the code does |
| `overclaim` | a firm growth heavier than the answer |
| **`missed`** | a firm growth **lighter** than the answer: said light, actually heavy |
| `missed_if_condition` | the same, against the heavy side of a conditional answer |
| `truth_undecided`, `route_missing`, `route_ambiguous` | not scored |

The columns of the table: `agree` = `match` + `bound_tight` + `conditional_ok`; `missed` includes
`missed_if_condition`; `other` = `bound_loose` + `bound_on_conditional` + `unverifiable`.

A category is decided by degree alone (O(1) = 0, O(N) = 1, O(N*M) = 2). The first manual check also counted a wrong
table as "too large"; this tool does not, so its `bound_over` is not the same count as the earlier "too large".
`bound_on_conditional` is kept apart from `bound_over` on purpose: a lower bound that rests on a condition may be
untrue on the default path, but it is not above the heavy side.

## Scoring a runtime measurement

```bash
php benchmarks/real-apps/score.php \
  --runtime=firefly-iii:R1=/path/to/run-R1 --runtime=firefly-iii:R2=/path/to/run-R2 \
  --set=round1-target --set=round1-control --rows
```

`/path/to/run-R1` is the directory a measurement run writes, with `routes.jsonl` and `attempts.jsonl`. Only the
`query` axis of the answers is used. The classes (same definitions as the first decisive experiment):

| Class | Meaning |
| --- | --- |
| A | the code says O(N) or heavier, and the measurement found growth |
| B | the code says O(N) or heavier, and the measurement did not reach the handler or learned nothing (the reason is counted) |
| C | the code says O(N) or heavier, and the measurement said O(1): **a false O(1)** |
| D | the code says O(1) or "conditional", and the measurement said O(1) or showed growth |
| D_candidate | the same answers, but the measurement said nothing |
| E | the answer is not firm enough (confidence below medium, or not decided) |

The report also splits D into *confirmed* (the code says O(1) and the measurement said O(1), or the code says
"conditional" and the measurement showed growth) and *doubtful* (every other D, above all a conditional answer whose
response size is the same at every scale, so it is not known that the condition held). `D_candidate` is not counted in D.
The split does not change `A / (A+B+C)`.

A verdict counts only when the request reached the handler; a verdict taken from a response that stopped at
authentication, an exception, a missing argument or a client error is ignored. `A / (A+B+C)` leaves out conditional
routes. `--unreached-bytes=N` names a response size known to be a prompt and not the handler (the first run of
Firefly III needed `48` for a two-factor prompt); it is off unless given. A run is `bytes_flat` when the response size is
the same at every scale: a C with a flat response is the strongest sign that the generated rows never reached it.

## Comparing two outputs

Run the same command on the output before and after a change and compare the `all` row (or the JSON `summary`). A
change that should not move any answer shows identical counts; a change that makes peak-sim bolder shows up first in
`missed`, `overclaim` and `bound_over`.

## Adding answers

Append routes to `routes` with a new `sets` name. Decide an answer by reading the code, with `file:line` evidence and the
commit in `apps`. Keep the apps' code out of the file. `Truth::load` refuses a file that does not have the shape above;
the test `RealAppsStaticScorerTest` also checks that no environment details (absolute paths) are present.
