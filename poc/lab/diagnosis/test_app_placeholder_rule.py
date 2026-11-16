"""Tests of app_placeholder_rule.py and of the committed rule.json. No Docker and no capture file needed.

    python3 -m unittest poc/lab/diagnosis/test_app_placeholder_rule.py
"""
import json
import sys
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

import app_placeholder_rule as rule  # noqa: E402

RECORD = HERE.parents[2] / "docs" / "research" / "lab-data" / "diagnosis" / "app-placeholder-risk" / "rule.json"


class FixedPart(unittest.TestCase):
    def test_a_plain_statement_has_all_its_values_fixed(self):
        self.assertEqual(rule.fixed_of("select * from t where a = ? and b = ?", 2), 2)

    def test_the_values_of_a_list_are_not_fixed(self):
        self.assertEqual(rule.fixed_of("select * from t where type = ? and id in (?, ?, ?, ?)", 5), 1)

    def test_a_literal_the_app_wrote_is_no_value(self):
        # `limit 20` is a `?` in the pattern; in the statement it is no value, so it is not counted
        self.assertEqual(rule.fixed_of("select * from t where id in (?, ?) limit 20", 2), 0)

    def test_an_insert_of_rows_has_no_fixed_part(self):
        self.assertEqual(rule.fixed_of("insert into t (a, b) values (?, ?), (?, ?)", 4), 0)

    def test_a_list_of_one_is_a_list(self):
        self.assertEqual(rule.fixed_of("select * from t where id in (?)", 1), 0)


class ValuesAt(unittest.TestCase):
    PAGE = {"scale": 30000, "values": 18004, "fixed": 3}

    def test_the_figures_the_records_give(self):
        self.assertEqual(rule.values_at(self.PAGE, 30000), 18004)
        self.assertEqual(rule.values_at(self.PAGE, 10000), 6004)
        self.assertEqual(rule.values_at(self.PAGE, 300000), 180013)

    def test_the_part_that_is_not_a_list_stays(self):
        self.assertEqual(rule.values_at({"scale": 1000, "values": 5, "fixed": 5}, 10**9), 5)

    def test_rounded_up(self):
        self.assertEqual(rule.values_at({"scale": 30000, "values": 601, "fixed": 1}, 101), 1 + 3)


class Record(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.doc = json.loads(RECORD.read_text())

    def test_the_cells_are_what_the_rule_gives_on_the_statements(self):
        cells = rule.judged(self.doc["statements"])
        self.assertEqual(cells, self.doc["judged_on_the_lab_records"])

    def test_no_miss_and_no_false_alarm_and_no_route_marked_without_a_failure_on_record(self):
        for c in self.doc["judged_on_the_lab_records"]:
            self.assertNotIn(c.get("verdict"), ("miss", "false_alarm"), c)
            self.assertEqual(c["marked_routes_with_no_failure_on_record"], [], c)

    def test_the_two_failures_on_record_are_hits(self):
        hits = {(c["app"], c["rows"]) for c in self.doc["judged_on_the_lab_records"] if c.get("verdict") == "hit"}
        self.assertEqual(hits, {("bookstack", 200000), ("bookstack", 300000), ("fixtures", 30000)})

    def test_the_book_page_line_is_4_plus_0_6_per_row(self):
        page = self.doc["book_page_statement"]
        self.assertEqual(page["values_at_10000_rows"], 6004)
        self.assertEqual(page["values_at_30000_rows"], 18004)
        self.assertAlmostEqual(page["line_through_the_two"]["per_row"], 0.6)
        self.assertAlmostEqual(page["line_through_the_two"]["constant"], 4.0)

    def test_the_rule_made_at_30000_rows_gives_the_value_seen_at_10000(self):
        self.assertEqual(self.doc["book_page_statement"]["rule_from_30000_gives_at_10000"], 6004)

    def test_no_route_but_the_two_has_a_statement_of_more_than_1000_values_at_300000_rows(self):
        big = [s for s in self.doc["statements"] if rule.values_at(s, 300000) > 1000]
        self.assertEqual(sorted({(s["app"], s["route"]) for s in big}), [("bookstack", "GET /books/{slug}"), ("fixtures", "POST /ok/bulk")])


if __name__ == "__main__":
    unittest.main()
