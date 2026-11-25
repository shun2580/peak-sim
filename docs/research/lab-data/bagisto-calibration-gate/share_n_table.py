#!/usr/bin/env python3
"""Makes the shared-table copy of an estimate: in `route_index`, every entry whose axis is N and whose table is null gets the table `n_shared`.

    python3 -I share_n_table.py <estimate.json> <copy.json> <proof.json>

Nothing else is changed. The proof states the sha256 of both files, how many entries were changed, and that the copy equals the original once
the table field of exactly those entries is set back to null. Exit 0 only if that check holds and `n_shared` is not already a name in the file.
"""
import copy
import hashlib
import json
import re
import sys

NAME = "n_shared"


def sha(path):
    with open(path, "rb") as f:
        return hashlib.sha256(f.read()).hexdigest()


def main(src, dst, proof_path):
    with open(src, "r", encoding="utf-8") as f:
        text = f.read()
    original = json.loads(text)
    if NAME in text:
        print("the name %s is already in the estimate" % NAME, file=sys.stderr)
        return 3
    # byte-level edit: only inside the route_index array, only `"table": null` that follows `"axis": "N"` in the same entry
    a = text.index('"route_index": [')
    b = text.index("\n    ]", a)  # the end of the route_index array (4-space layout of the tool)
    block = text[a:b]
    pattern = re.compile(r'("axis": "N",\n\s+"table": )null')
    new_block, count = pattern.subn(r'\1"%s"' % NAME, block)
    out = text[:a] + new_block + text[b:]
    with open(dst, "w", encoding="utf-8") as f:
        f.write(out)
    changed = json.loads(out)
    idx = [i for i, e in enumerate(original["route_index"]) if e.get("axis") == "N" and e.get("table") is None]
    back = copy.deepcopy(changed)
    for i in idx:
        back["route_index"][i]["table"] = None
    only_table = back == original and count == len(idx)
    other_keys_same = {k: v for k, v in changed.items() if k != "route_index"} == {k: v for k, v in original.items() if k != "route_index"}
    # every differing line is a table line
    ol, nl = text.split("\n"), out.split("\n")
    diff_lines = [(x, y) for x, y in zip(ol, nl) if x != y]
    only_table_lines = len(ol) == len(nl) and all(x.strip() == '"table": null,' or x.strip() == '"table": null' for x, _ in diff_lines) and all(y.strip().startswith('"table": "%s"' % NAME) for _, y in diff_lines)
    proof = {
        "original_sha256": sha(src),
        "copy_sha256": sha(dst),
        "route_index_entries": len(original["route_index"]),
        "entries_changed": count,
        "changed_lines": len(diff_lines),
        "every_changed_line_is_a_table_line": only_table_lines,
        "copy_equals_original_after_resetting_those_table_fields": only_table,
        "everything_outside_route_index_identical": other_keys_same,
        "pseudo_table_name": NAME,
        "changed_routes": [original["route_index"][i]["route"] for i in idx],
    }
    with open(proof_path, "w", encoding="utf-8") as f:
        json.dump(proof, f, indent=2, ensure_ascii=False)
        f.write("\n")
    print(json.dumps({k: v for k, v in proof.items() if k != "changed_routes"}, indent=2))
    return 0 if (only_table and other_keys_same and only_table_lines) else 4


if __name__ == "__main__":
    sys.exit(main(*sys.argv[1:4]))
