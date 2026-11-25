#!/usr/bin/env python3
"""Make the route list of the Bagisto lab backtest from the third capture.

The rule is in docs/simulator-spec.md section 7.4.7 and decisions D262 (decisions 2, 3, 7 and
the addendum on the column). It is fixed before any load is put on the lab and is not changed
after the list is committed. Standard library only; the output depends on nothing but the
files named on the command line, so it can be run again and compared.

    python3 -I select_lab_routes.py --capture-dir <capture output dir> \
        --arrived-list <list-arrived.txt> --n-tables <n-tables.txt> \
        --profile <bagisto.php> --out <empty or new dir>

Steps (fixed):
1. Candidates: the routes of the arrived list (the 19 routes of the committed list) whose
   method is GET. A listed route that is missing from the capture is a stop.
2. Write guard: every statement type of a candidate is read from capture.json. A statement
   whose kind is insert, update, delete or replace is a write; the tables it writes are read
   from its pattern. A candidate that writes a table of the N list (123 tables) is taken out.
   The tables written by the other candidates are listed, by table, as the tables the lab may
   write. A candidate that the profile marks `state_changing` is a stop. A write statement
   whose kind is empty, a kind the script does not know, or a pattern the script cannot read
   is a stop.
3. Order: `routes[].totals["30000"].rows_sum`, largest first, ties by the string
   "METHOD URI". A missing 30000 entry, a null `rows_sum` or `unread_events` other than 0 is a
   stop. The first 8 are taken (all, if fewer than 8). Fewer than 6 is a stop and nothing is
   built. The orders by `cold_rows_examined` and by the `rows.examined` of routes.jsonl are
   written beside it for reading only; they decide nothing.
4. Output: candidates.tsv, excluded.tsv, allowed-tables.tsv, selected.tsv (numbered 1..n),
   reference-orders.tsv, inputs.sha256, summary.txt.

A stop writes nothing and exits with 4; the message names the route and the reason. The
list is not fixed up by hand.
"""
import argparse
import hashlib
import json
import os
import re
import sys

SCALE = "30000"
TAKE = 8
MINIMUM = 6
N_TABLE_COUNT = 123
WRITE_KINDS = ("insert", "update", "delete", "replace")
READ_KINDS = ("select", "show", "explain")
WRITE_WORDS = re.compile(
    r"\b(insert|replace|update|delete|truncate|alter|create|drop|call|load|rename|grant)\b")


class Stop(Exception):
    """A case the rule says must be handed back instead of decided here."""


def sha256_of(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def strip_comments(sql):
    sql = re.sub(r"/\*.*?\*/", " ", sql, flags=re.S)
    sql = re.sub(r"(--|#)[^\n]*", " ", sql)
    return sql.strip()


def ident(token):
    """`db`.`table` or table -> table (lower case); None if it is not a plain name."""
    token = token.strip().rstrip(",;")
    parts = [p.strip("`") for p in token.split(".")]
    last = parts[-1]
    if not re.fullmatch(r"[A-Za-z0-9_$]+", last or ""):
        return None
    return last.lower()


def tables_written(kind, sql):
    """The tables a write statement writes. Raises Stop when it cannot be read plainly."""
    s = strip_comments(sql)
    low = re.sub(r"\s+", " ", s.lower())
    if kind in ("insert", "replace"):
        m = re.match(
            r"(?:insert|replace) (?:(?:low_priority|delayed|high_priority|ignore) )*(?:into )?"
            r"((?:`[^`]+`|[a-z0-9_$]+)(?:\.(?:`[^`]+`|[a-z0-9_$]+))?)", low)
        name = ident(m.group(1)) if m else None
        if name is None:
            raise Stop("cannot read the table of an %s statement: %s" % (kind, low[:120]))
        return [name]
    if kind == "update":
        m = re.match(r"update (?:(?:low_priority|ignore) )*(.*?) set ", low)
        if not m or "(" in m.group(1) or "select" in m.group(1):
            raise Stop("cannot read the tables of an update statement: %s" % low[:120])
        out = []
        for item in re.split(r",| (?:(?:inner|left|right|cross|natural|straight_join|outer) )*join ",
                             " " + m.group(1) + " "):
            item = re.split(r" on | using ", item.strip())[0].strip()
            if not item:
                continue
            name = ident(item.split(" ")[0])
            if name is None:
                raise Stop("cannot read a table name of an update statement: %s" % low[:120])
            out.append(name)
        if not out:
            raise Stop("no table found in an update statement: %s" % low[:120])
        return sorted(set(out))
    if kind == "delete":
        m = re.match(r"delete (?:(?:low_priority|quick|ignore) )*from "
                     r"((?:`[^`]+`|[a-z0-9_$]+)(?:\.(?:`[^`]+`|[a-z0-9_$]+))?)(?: |$)", low)
        if not m:
            raise Stop("a delete statement that is not 'delete from <one table>': %s" % low[:120])
        rest = low[m.end():]
        if re.match(r"(?:as )?[a-z0-9_`]+ (?:inner |left |right )?join |using |, ", rest):
            raise Stop("a delete statement over more than one table: %s" % low[:120])
        name = ident(m.group(1))
        if name is None:
            raise Stop("cannot read the table of a delete statement: %s" % low[:120])
        return [name]
    raise Stop("not a write kind: %s" % kind)


def classify_statements(route, statement_types):
    """-> (writes {table: sorted kinds}, notes [text]). Raises Stop."""
    writes = {}
    notes = []
    for st in statement_types:
        kind = (st.get("kind") or "").lower()
        pattern = st.get("pattern") or ""
        if not pattern.strip():
            raise Stop("%s: a statement type with an empty pattern (id %s)" % (route, st.get("id")))
        body = re.sub(r"\bfor update\b", " ", re.sub(r"\s+", " ", strip_comments(pattern).lower()))
        if kind in WRITE_KINDS:
            try:
                names = tables_written(kind, pattern)
            except Stop as exc:
                raise Stop("%s: %s" % (route, exc))
            for name in names:
                writes.setdefault(name, set()).add(kind)
        elif kind == "":
            if WRITE_WORDS.search(body):
                raise Stop("%s: a statement of empty kind that may write: %s" % (route, body[:120]))
            notes.append("empty kind, no write word (id %s)" % st.get("id"))
        elif kind == "with":
            if WRITE_WORDS.search(body):
                raise Stop("%s: a WITH statement that may write: %s" % (route, body[:120]))
        elif kind in READ_KINDS:
            pass
        else:
            raise Stop("%s: a statement kind the rule does not cover: %s" % (route, kind))
    return {t: sorted(k) for t, k in writes.items()}, notes


def read_lines(path):
    with open(path, encoding="utf-8") as fh:
        return [ln.strip() for ln in fh.read().splitlines() if ln.strip()]


def profile_state_changing(path):
    with open(path, encoding="utf-8") as fh:
        text = fh.read()
    start = text.find("$profile['state_changing']")
    if start < 0:
        raise Stop("the profile has no state_changing list to read")
    end = text.find("];\n\n", start)
    block = text[start:end if end > 0 else len(text)]
    return re.findall(r"'route'\s*=>\s*'([^']+)'", block)


def select(capture, arrived, n_tables, state_changing, jsonl_rows):
    if len(n_tables) != len(set(n_tables)):
        raise Stop("the N table list has a repeated name")
    if len(n_tables) != N_TABLE_COUNT:
        raise Stop("the N table list has %d names, not %d" % (len(n_tables), N_TABLE_COUNT))
    by_route = {}
    for r in capture["routes"]:
        by_route[r["route"]] = r
    candidates = []
    notget = []
    for name in arrived:
        if name not in by_route:
            raise Stop("%s: in the arrived list but not in the capture" % name)
        r = by_route[name]
        if r["method"] != "GET" or name != "%s %s" % (r["method"], r["uri"]):
            notget.append(name)
        else:
            candidates.append(r)
    marked = sorted(r["route"] for r in candidates if r["route"] in set(state_changing))
    if marked:
        raise Stop("state_changing routes among the candidates: %s" % ", ".join(marked))
    ntab = set(t.lower() for t in n_tables)
    rows = []
    for r in candidates:
        t = (r.get("totals") or {}).get(SCALE)
        if t is None:
            raise Stop("%s: no totals for %s rows" % (r["route"], SCALE))
        if t.get("rows_sum") is None:
            raise Stop("%s: rows_sum is null at %s rows" % (r["route"], SCALE))
        if t.get("unread_events") != 0:
            raise Stop("%s: unread_events is not 0 at %s rows (rows_sum is a sum of the statements that could be read)"
                       % (r["route"], SCALE))
        writes, notes = classify_statements(r["route"], r["statement_types"])
        hit = sorted(t_ for t_ in writes if t_ in ntab)
        jr = jsonl_rows.get(r["route"])
        examined = None
        if jr and SCALE in map(str, jr.get("rows", {}).get("scales", [])):
            examined = jr["rows"]["examined"][[str(x) for x in jr["rows"]["scales"]].index(SCALE)]
        rows.append({
            "route": r["route"], "rows_sum": t["rows_sum"],
            "cold_rows_examined": t.get("cold_rows_examined"),
            "jsonl_examined": examined, "statements": t.get("statements"),
            "writes": writes, "notes": notes, "in_n": hit,
        })
    kept = [x for x in rows if not x["in_n"]]
    excluded = [x for x in rows if x["in_n"]]
    kept.sort(key=lambda x: (-x["rows_sum"], x["route"]))
    if len(kept) < MINIMUM:
        raise Stop("only %d candidates remain after the write guard; fewer than %d, nothing is built"
                   % (len(kept), MINIMUM))
    chosen = kept[:TAKE]
    allowed = {}
    for x in chosen:
        for table, kinds in x["writes"].items():
            e = allowed.setdefault(table, {"kinds": set(), "routes": []})
            e["kinds"].update(kinds)
            e["routes"].append(x["route"])
    return rows, excluded, kept, chosen, allowed, notget


def fmt(v):
    return "" if v is None else str(v)


def rank_by(items, key):
    present = [x for x in items if x[key] is not None]
    present.sort(key=lambda x: (-x[key], x["route"]))
    return {x["route"]: i + 1 for i, x in enumerate(present)}


def write_outputs(out, rows, excluded, kept, chosen, allowed, notget, inputs):
    os.makedirs(out, exist_ok=True)
    num = {x["route"]: i + 1 for i, x in enumerate(chosen)}
    exc = {x["route"]: x for x in excluded}

    def tsv(name, header, lines):
        with open(os.path.join(out, name), "w", encoding="utf-8") as fh:
            fh.write("\t".join(header) + "\n")
            for ln in lines:
                fh.write("\t".join(ln) + "\n")

    def state(x):
        if x["route"] in exc:
            return "excluded: writes a table of the N list"
        if x["route"] in num:
            return "selected %d" % num[x["route"]]
        return "not selected: below the first %d" % TAKE

    tsv("candidates.tsv",
        ["route", "rows_sum_30000", "statements_30000", "writes", "tables_written", "result"],
        [[x["route"], fmt(x["rows_sum"]), fmt(x["statements"]),
          "yes" if x["writes"] else "no", ",".join(sorted(x["writes"])), state(x)]
         for x in sorted(rows, key=lambda x: (-x["rows_sum"], x["route"]))])
    tsv("excluded.tsv", ["route", "reason", "n_list_tables_written", "all_tables_written"],
        [[x["route"], "writes a table of the N list", ",".join(x["in_n"]), ",".join(sorted(x["writes"]))]
         for x in sorted(excluded, key=lambda x: x["route"])])
    tsv("allowed-tables.tsv", ["table", "statement_kinds", "selected_routes_that_write_it"],
        [[t, ",".join(sorted(e["kinds"])), "; ".join(sorted(e["routes"]))]
         for t, e in sorted(allowed.items())])
    tsv("selected.tsv", ["number", "route", "rows_sum_30000"],
        [[str(i + 1), x["route"], fmt(x["rows_sum"])] for i, x in enumerate(chosen)])
    ra, rb, rc = rank_by(kept, "rows_sum"), rank_by(kept, "cold_rows_examined"), rank_by(kept, "jsonl_examined")
    tsv("reference-orders.tsv",
        ["route", "rank_rows_sum", "rank_cold_rows_examined", "rank_rows_examined_jsonl",
         "cold_rows_examined_30000", "rows_examined_jsonl_30000"],
        [[x["route"], fmt(ra.get(x["route"])), fmt(rb.get(x["route"])), fmt(rc.get(x["route"])),
          fmt(x["cold_rows_examined"]), fmt(x["jsonl_examined"])]
         for x in sorted(kept, key=lambda x: ra[x["route"]])])
    with open(os.path.join(out, "inputs.sha256"), "w", encoding="utf-8") as fh:
        for label, digest in inputs:
            fh.write("%s  %s\n" % (digest, label))
    top_a = [x["route"] for x in sorted(kept, key=lambda x: ra[x["route"]])[:TAKE]]
    top_b = [x["route"] for x in sorted([y for y in kept if y["cold_rows_examined"] is not None],
                                        key=lambda x: rb[x["route"]])[:TAKE]]
    top_c = [x["route"] for x in sorted([y for y in kept if y["jsonl_examined"] is not None],
                                        key=lambda x: rc[x["route"]])[:TAKE]]
    empty_notes = sum(len(x["notes"]) for x in rows)
    lines = [
        "candidates (listed routes that are GET): %d" % len(rows),
        "listed routes that are not GET: %d" % len(notget),
        "taken out (write a table of the N list): %d" % len(excluded),
        "remaining after the write guard: %d" % len(kept),
        "selected: %d (cap %d, minimum %d)" % (len(chosen), TAKE, MINIMUM),
        "tables the lab may write: %d" % len(allowed),
        "empty-kind statements read as not writing (no write word): %d" % empty_notes,
        "the first %d by rows_sum, by cold_rows_examined and by the jsonl rows.examined are the same set: %s"
        % (TAKE, "yes" if set(top_a) == set(top_b) == set(top_c) else "no"),
        "the three first-%d lists are in the same order: %s"
        % (TAKE, "yes" if top_a == top_b == top_c else "no"),
    ]
    with open(os.path.join(out, "summary.txt"), "w", encoding="utf-8") as fh:
        fh.write("\n".join(lines) + "\n")
    return lines


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--capture-dir", required=True)
    ap.add_argument("--arrived-list", required=True)
    ap.add_argument("--n-tables", required=True)
    ap.add_argument("--profile", required=True)
    ap.add_argument("--out", required=True)
    args = ap.parse_args(argv)
    cap_path = os.path.join(args.capture_dir, "capture.json")
    jsonl_path = os.path.join(args.capture_dir, "routes.jsonl")
    try:
        with open(cap_path, encoding="utf-8") as fh:
            capture = json.load(fh)
        with open(jsonl_path, encoding="utf-8") as fh:
            jsonl_rows = {}
            for ln in fh:
                if ln.strip():
                    j = json.loads(ln)
                    jsonl_rows["%s %s" % (j["method"], j["route"])] = j
        arrived = read_lines(args.arrived_list)
        n_tables = read_lines(args.n_tables)
        if os.path.exists(args.out) and os.listdir(args.out):
            raise Stop("the output directory is not empty")
        state_changing = profile_state_changing(args.profile)
        result = select(capture, arrived, n_tables, state_changing, jsonl_rows)
    except Stop as exc:
        print("STOP: %s" % exc)
        return 4
    rows, excluded, kept, chosen, allowed, notget = result
    inputs = [("capture.json", sha256_of(cap_path)), ("routes.jsonl", sha256_of(jsonl_path)),
              ("list-arrived.txt", sha256_of(args.arrived_list)),
              ("n-tables.txt", sha256_of(args.n_tables)),
              ("bagisto.php", sha256_of(args.profile)),
              ("select_lab_routes.py", sha256_of(os.path.abspath(__file__)))]
    for line in write_outputs(args.out, rows, excluded, kept, chosen, allowed, notget, inputs):
        print(line)
    return 0


if __name__ == "__main__":
    sys.exit(main())
