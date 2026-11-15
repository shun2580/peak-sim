"""Tests of app_memory_factor.py (reads files only; no Docker, no lab). Run: python3 -m unittest poc/lab/diagnosis/test_app_memory_factor.py
A precondition that is not met is a failure, not a skip."""
import json
import os
import sys
import unittest
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
import app_memory_factor as f  # noqa: E402

REPO = Path(__file__).resolve().parents[3]
RECORD = REPO / "docs/research/lab-data/diagnosis/app-memory-risk/factor.json"


def rec(predicted, measured, app="a", route="r"):
    return {"app": app, "route": route, "predicted": predicted, "measured": measured, "ratio": measured / predicted}


class FactorTest(unittest.TestCase):
    def test_the_factor_is_the_largest_ratio_rounded_up_to_two_decimals(self):
        self.assertEqual(1.51, f.factor_of([rec(200e6, 300e6), rec(200e6, 301.4e6)]))
        self.assertEqual(1.5, f.factor_of([rec(200e6, 300e6)]))

    def test_a_record_under_the_floor_is_not_used(self):
        small = rec(6e6, 17e6)  # ratio 2.83, measured 17 MB
        big = rec(200e6, 210e6)
        self.assertEqual(1.05, f.factor_of([small, big]))
        self.assertEqual(2.84, f.factor_of([small, big], floor=0))

    def test_the_gap_names_the_two_groups(self):
        g = f.gap_of([rec(6e6, 17e6), rec(40e6, 50e6), rec(200e6, 260e6), rec(300e6, 310e6)])
        self.assertEqual({"largest_measured_below_floor": 50e6, "smallest_measured_at_or_above_floor": 260e6}, g)

    def test_the_formula_is_a_plus_b_n_to_the_k(self):
        self.assertAlmostEqual(1000 + 2 * 3000, f.evaluate_formula(1000.0, 2.0, 1.0, 3000))
        self.assertAlmostEqual(5.0 + 3 * 100 ** 0.5, f.evaluate_formula(5.0, 3.0, 0.5, 100))

    def test_the_accuracy_state_of_a_route(self):
        acc = {"within": ["w"], "outside": ["o"]}
        self.assertEqual(["within", "outside", "not_checked"], [f.accuracy_state(acc, r) for r in ("w", "o", "x")])


class RecordTest(unittest.TestCase):
    def test_the_committed_record_is_what_the_script_gives_now(self):
        self.assertTrue(RECORD.is_file(), str(RECORD))
        self.assertEqual(RECORD.read_text(), json.dumps(f.build(REPO), indent=2) + "\n")

    def test_the_floor_is_inside_the_gap_between_the_two_groups(self):
        r = json.loads(RECORD.read_text())
        self.assertLess(r["gap_between_the_groups"]["largest_measured_below_floor"], r["floor_bytes"])
        self.assertLess(r["floor_bytes"], r["gap_between_the_groups"]["smallest_measured_at_or_above_floor"])

    def test_no_record_of_an_unseen_count_is_read(self):
        self.assertEqual((10000, 30000, 50000, 100000, 200000), f.LAB_COUNTS)
        self.assertTrue(all(c < 300000 for c in f.LAB_COUNTS))

    def test_the_rule_misses_no_request_that_ran_out_of_memory_on_the_lab_records(self):
        r = json.loads(RECORD.read_text())
        self.assertEqual(0, r["tally_on_the_lab_records"].get("miss", 0))
        self.assertEqual(1, r["tally_on_the_lab_records"].get("hit", 0))
        self.assertEqual(40, sum(r["tally_on_the_lab_records"].values()))

    def test_every_request_that_did_not_answer_is_accounted_for(self):
        for c in f.LAB_COUNTS:
            for route in f.LAB_ROUTES:
                status = f.lab_status(REPO, c, route)
                if status not in (200, 302):
                    self.assertIn((c, route), f.STOPPED, "%s at %d answered %s" % (route, c, status))


if __name__ == "__main__":
    unittest.main()
