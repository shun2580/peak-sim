"""Tests of app_limit_factor.py (reads files only; no Docker, no lab). Run: python3 -m unittest poc/lab/diagnosis/test_app_limit_factor.py
A precondition that is not met is a failure, not a skip."""
import json
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
import app_limit_factor as f  # noqa: E402

REPO = Path(__file__).resolve().parents[3]
RECORD = REPO / "docs/research/lab-data/diagnosis/app-limit-risk/factor.json"


def rec(php, conv, withheld=False, cut=False, est=0.0, safe=0.0, rows=1000):
    return {"rows": rows, "route": "r", "cut_by_limit": cut, "php_cpu_s": php, "php_cpu_s_is_uncut_stand_in": cut,
            "db_cpu_s": conv, "db_cpu_reading_withheld": withheld,
            "ratio": None if withheld or conv <= 0 else php / conv, "estimate_point_s": est, "estimate_safe_s": safe}


class FactorTest(unittest.TestCase):
    def test_the_factor_is_the_largest_ratio_rounded_up_to_two_decimals(self):
        self.assertEqual(2.35, f.factor_of([rec(2.0, 1.0), rec(2.341, 1.0)]))

    def test_a_withheld_record_is_not_used(self):
        self.assertEqual(2.0, f.factor_of([rec(2.0, 1.0), rec(9.0, 1.0, withheld=True)]))

    def test_a_marked_route_is_a_hit_when_it_was_cut_and_a_false_alarm_when_it_was_not(self):
        out = f.judge([rec(1, 1, cut=True, est=10.0), rec(1, 1, est=10.0)], 5.0, 30.0)
        self.assertEqual(["hit", "false_alarm"], [v["outcome"] for v in out])

    def test_an_unmarked_route_is_a_miss_when_it_was_cut(self):
        out = f.judge([rec(1, 1, cut=True, est=1.0), rec(1, 1, est=1.0)], 5.0, 30.0)
        self.assertEqual(["miss", "true_negative"], [v["outcome"] for v in out])

    def test_the_larger_of_point_and_safe_side_counts(self):
        out = f.judge([rec(1, 1, est=1.0, safe=7.0)], 5.0, 30.0)
        self.assertTrue(out[0]["marked"])

    def test_the_line_itself_is_marked(self):
        self.assertTrue(f.judge([rec(1, 1, est=6.0)], 5.0, 30.0)[0]["marked"])


class RecordTest(unittest.TestCase):
    def test_the_committed_record_is_what_the_script_gives_now(self):
        self.assertTrue(RECORD.is_file(), str(RECORD))
        self.assertEqual(RECORD.read_text(), json.dumps(f.build(REPO, 30.0), indent=2) + "\n")

    def test_no_record_of_an_unseen_count_is_read(self):
        self.assertEqual((10000, 30000, 100000), f.COUNTS)

    def test_the_rule_misses_no_cut_request_on_the_records_and_marks_the_held_out_count(self):
        r = json.loads(RECORD.read_text())
        self.assertEqual(0, r["tally_on_the_records"].get("miss", 0))
        self.assertTrue(all(v["marked"] for v in r["judged_at_100000_with_that_factor"] if v["cut_by_limit"]))


if __name__ == "__main__":
    unittest.main()
