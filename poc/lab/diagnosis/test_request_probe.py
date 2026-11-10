"""Tests of the pure parts of request_probe.py (no Docker, no lab). Run: python3 -m unittest poc/lab/diagnosis/test_request_probe.py
A precondition that is not met is a failure, not a skip."""
import json
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(__file__))
import request_probe as rp  # noqa: E402

CPU = "usage_usec 1000\nuser_usec 600\nsystem_usec 400\n"
STATUS = "Handler_read_key\t10\nHandler_read_next\t5\nQuestions\t7\nCom_select\t3\nSomething_else\t99\n"


class ParseTest(unittest.TestCase):
    def test_cpu_stat(self):
        self.assertEqual(1000, rp.parse_cpu_stat(CPU))

    def test_cpu_stat_without_the_line_is_an_error(self):
        with self.assertRaises(ValueError):
            rp.parse_cpu_stat("user_usec 1\n")

    def test_global_status_keeps_only_the_counters(self):
        self.assertEqual({"Handler_read_key": 10, "Handler_read_next": 5, "Questions": 7, "Com_select": 3}, rp.parse_global_status(STATUS))

    def test_snapshot_reads_both_orders(self):
        self.assertEqual(1000, rp.parse_snapshot(STATUS + CPU)["usage_usec"])
        self.assertEqual(1000, rp.parse_snapshot(CPU + STATUS)["usage_usec"])
        self.assertEqual(15, rp.handler_total(rp.parse_snapshot(CPU + STATUS)["counters"]))

    def test_nginx_requests(self):
        text = "Active connections: 1 \nserver accepts handled requests\n 3249 3249 3251 \nReading: 0 Writing: 1 Waiting: 0 \n"
        self.assertEqual(3251, rp.parse_nginx_requests(text))
        with self.assertRaises(ValueError):
            rp.parse_nginx_requests("nothing")

    def test_probe_line_plain_and_k6_quoted(self):
        plain = 'PROBE {"status":200,"duration_ms":12.5}'
        quoted = 'time="x" level=info msg="PROBE {\\"status\\":500,\\"duration_ms\\":30000}" source=console'
        self.assertEqual(200, rp.parse_probe_line("noise\n" + plain)["status"])
        self.assertEqual(500, rp.parse_probe_line(quoted)["status"])
        self.assertIsNone(rp.parse_probe_line("no result here"))

    def test_attempt_lines_only(self):
        text = 'app support: x\nbooted: {}\n\n{"route":"r","attempt":1,"queries":11}\n\n{"route":"r","attempt":2,"queries":8}\n'
        self.assertEqual([1, 2], [a["attempt"] for a in rp.parse_attempts(text)])


class WindowTest(unittest.TestCase):
    def snap(self, usage, **c):
        return {"usage_usec": usage, "counters": c}

    def test_delta(self):
        d = rp.delta(self.snap(100, Questions=5, Handler_read_key=1), self.snap(160, Questions=9, Handler_read_key=11))
        self.assertEqual(60, d["cpu_usec"])
        self.assertEqual({"Questions": 4, "Handler_read_key": 10}, d["counters"])

    def test_null_profile_takes_medians(self):
        nulls = [{"cpu_usec": c, "wall_s": 0.5, "counters": {"Questions": q}} for c, q in [(10, 1), (30, 3), (20, 2)]]
        n = rp.null_profile(nulls)
        self.assertEqual(20, n["cpu_usec"])
        self.assertEqual(2, n["counters"]["Questions"])
        self.assertEqual(3, n["samples"])

    def test_idle_rate_takes_the_null_off_and_never_goes_negative(self):
        null = {"cpu_usec": 19000.0, "wall_s": 0.5}
        self.assertAlmostEqual(9500.0, rp.idle_rate({"cpu_usec": 114000.0, "wall_s": 10.5}, null))
        self.assertEqual(0.0, rp.idle_rate({"cpu_usec": 1000.0, "wall_s": 10.5}, null))
        self.assertEqual(0.0, rp.idle_rate({"cpu_usec": 1000.0, "wall_s": 0.4}, null))

    def test_net_window_takes_off_the_null_and_the_idle_drift(self):
        null = {"cpu_usec": 19000.0, "wall_s": 0.5, "counters": {"Questions": 2.0, "Handler_read_key": 20.0}}
        raw = {"cpu_usec": 119000, "wall_s": 2.5, "counters": {"Questions": 12, "Handler_read_key": 220}}
        n = rp.net_window(raw, null, 10000.0)  # 2 s longer than the null: 20000 usec of drift
        self.assertAlmostEqual(80000.0, n["cpu_net_usec"])
        self.assertAlmostEqual(100000.0, n["cpu_minus_null_usec"])
        self.assertEqual(10.0, n["questions_net"])
        self.assertEqual(200.0, n["handlers_net"])

    def test_floor(self):
        null = {"cpu_usec_all": [18000, 19000, 20000]}
        self.assertTrue(rp.below_floor(5000.0, null))
        self.assertFalse(rp.below_floor(50000.0, null))
        # a reading smaller than the drift taken off it is not trusted either
        self.assertTrue(rp.below_floor(30000.0, null, idle_drift_usec=30000.0))
        self.assertFalse(rp.below_floor(30000.0, null, idle_drift_usec=10000.0))

    def test_settled_needs_a_quiet_run(self):
        self.assertFalse(rp.settled([0, 1, 2]))
        self.assertFalse(rp.settled([0, 100000, 100001, 100002]))
        self.assertTrue(rp.settled([0, 500000, 500020, 500050, 500070]))
        self.assertFalse(rp.settled([0, 1, 2, 900000]))
        # a poll's own overhead (about 40 ms) is not work
        self.assertTrue(rp.settled([i * 40000 for i in range(6)]))
        self.assertFalse(rp.settled([i * 300000 for i in range(6)]))


class EstimateTest(unittest.TestCase):
    ESTIMATE = {"routes": [
        {"route": "A", "queries": 8.0, "rows_examined": 200.0, "rows_examined_upper": None, "marked": False},
        {"route": "B", "queries": 100.0, "rows_examined": 1000.0, "rows_examined_upper": 4000.0, "marked": True},
    ]}

    def test_safe_rows_are_the_upper_side_only_for_a_marked_route(self):
        e = rp.estimate_routes(self.ESTIMATE)
        self.assertEqual(200.0, e["A"]["rows_safe"])
        self.assertEqual(4000.0, e["B"]["rows_safe"])

    def test_per_request_cpu_is_the_calibration_form(self):
        self.assertAlmostEqual(2.0 * 3 + 0.5 * 4, rp.per_request_cpu(2.0, 0.5, 3, 4))

    def test_ratio_has_no_value_on_zero_or_missing(self):
        self.assertEqual(2.0, rp.ratio(4.0, 2.0))
        self.assertIsNone(rp.ratio(4.0, 0))
        self.assertIsNone(rp.ratio(None, 2.0))

    def test_report_builds_ratios_and_withholds_a_floor_reading(self):
        null = {"cpu_usec": 19000.0, "wall_s": 0.5, "counters": {}, "cpu_usec_all": [18000, 19000, 20000]}
        def req(route, cpu_net):
            return {"kind": "request", "route": route, "phase": "cold", "nginx_requests_between_probes": 2,
                    "result": {"status": 200, "duration_ms": 10, "body_bytes": 5, "php_cpu_usec": 1000, "answered_after_s": 0.1,
                               "settle": {"waited_s": 3.0}},
                    "net": {"cpu_net_usec": cpu_net, "cpu_minus_null_usec": cpu_net, "idle_drift_usec": 0.0, "questions_net": 9, "handlers_net": 210}}
        http = [{"kind": "null", "profile": null}, {"kind": "idle", "raw": {}, "rate_usec_per_s": 1.0}]
        http += [req("A", 4000.0)] * 3 + [req("B", 400000.0)] * 3
        inproc = [{"route": r, "attempt": a, "queries": q, "rows_examined": rows, "status": 200}
                  for r, q, rows in [("A", 8, 100), ("B", 100, 500)] for a in (1, 2) for _ in range(3)]
        pred = {"calibration": {"coefficients": {"c_q": 0.001, "c_row": 0.00001}}}
        rep = rp.build_report(100000, http, inproc, self.ESTIMATE, pred)
        a, b = rep["routes"]
        self.assertTrue(a["cpu_below_measurement_floor"])
        self.assertIsNone(a["estimate_over_measured"]["cpu_point"])
        self.assertEqual(2.0, a["estimate_over_measured"]["rows_point"])
        self.assertFalse(b["cpu_below_measurement_floor"])
        self.assertAlmostEqual((0.001 * 100 + 0.00001 * 1000) / 0.4, b["estimate_over_measured"]["cpu_point"])
        self.assertAlmostEqual((0.001 * 100 + 0.00001 * 4000) / 0.4, b["estimate_over_measured"]["cpu_safe"])
        json.dumps(rep)  # the report is JSON


class ProcessListAndCountsTest(unittest.TestCase):
    def test_process_list_leaves_out_its_own_statement(self):
        text = ("5\troot\tlocalhost\tdb\tQuery\t30\texecuting\tSELECT 1\n"
                "9\troot\tlocalhost\tNULL\tQuery\t0\tinit\tSHOW FULL PROCESSLIST\n")
        rows = rp.parse_process_list(text)
        self.assertEqual(1, len(rows))
        self.assertEqual("5", rows[0]["id"])
        self.assertEqual("30", rows[0]["time_s"])

    def test_process_list_skips_short_lines(self):
        self.assertEqual([], rp.parse_process_list("garbage\n"))

    def test_table_counts_keep_the_three_tables(self):
        self.assertEqual({"entities": 100000, "images": 100000, "references": 100000},
                         rp.parse_table_counts("entities\t100000\nimages\t100000\nreferences\t100000\nother\t5\n"))

    def test_table_counts_ignore_non_numbers(self):
        self.assertEqual({}, rp.parse_table_counts("entities\tNULL\n"))


class ShellHelperTest(unittest.TestCase):
    def test_sh_survives_output_that_is_not_utf8(self):
        r = rp.sh(["python3", "-c", "import sys; sys.stdout.buffer.write(b'ok \\xe3 end')"])
        self.assertEqual(0, r.returncode)
        self.assertIn("ok", r.stdout)

    def test_sh_passes_stdin(self):
        r = rp.sh(["cat"], stdin="SELECT `references`")
        self.assertEqual("SELECT `references`", r.stdout)


class ScatterTest(unittest.TestCase):
    def test_range_larger_than_median_is_scatter(self):
        self.assertTrue(rp.scatter_exceeds_value([0.0014, 0.0035, 0.0089]))

    def test_steady_readings_are_not(self):
        self.assertFalse(rp.scatter_exceeds_value([0.0065, 0.0081, 0.0086]))

    def test_one_reading_is_not(self):
        self.assertFalse(rp.scatter_exceeds_value([1.0]))


class WithheldRatioTest(unittest.TestCase):
    def test_a_withheld_ratio_is_shown_with_its_value_and_reason(self):
        cell = rp.cpu_cell({"cpu_withheld_because": ["scatter"], "cpu_point_if_not_withheld": 0.693, "cpu_safe_if_not_withheld": 0.7})
        self.assertIn("withheld", cell)
        self.assertIn("0.693", cell)
        self.assertIn("scatter", cell)

    def test_a_kept_ratio_is_just_the_numbers(self):
        self.assertEqual("1.46 / 1.47", rp.cpu_cell({"cpu_withheld_because": [], "cpu_point": 1.46, "cpu_safe": 1.47}))


def _req(route, status, el, php, cpu, handlers, q):
    return {"kind": "request", "phase": "cold", "route": route, "result": {"status": status, "answered_after_s": el, "php_cpu_usec": php * 1e6},
            "net": {"cpu_net_usec": cpu * 1e6, "cpu_minus_null_usec": cpu * 1e6 + 5, "handlers_net": handlers, "questions_net": q}}


class CompareTest(unittest.TestCase):
    def test_only_cold_requests_of_the_route_are_taken(self):
        rows = [_req("GET /a/zip", 500, 50, 30, 25, 800, 96), _req("GET /b", 200, 1, 1, 1, 1, 1),
                {"kind": "request", "phase": "warmup", "route": "GET /a/zip", "result": {}, "net": None}]
        self.assertEqual(1, len(rp.cold_requests(rows, "/zip")))

    def test_medians_and_ratios(self):
        cut = rp.summarize_cold([_req("z", 500, 50, 30, 25, 800, 96), _req("z", 500, 52, 31, 26, 810, 97), _req("z", 500, 51, 30, 25, 805, 96)])
        unc = rp.summarize_cold([_req("z", 200, 70, 40, 34, 835, 120)] * 3)
        c = rp.compare_records(cut, unc)
        self.assertEqual(51, c["elapsed_s"]["cut"])
        self.assertAlmostEqual(34 / 25, c["db_cpu_net_s"]["uncut_over_cut"])
        self.assertAlmostEqual(96 / 120, c["questions"]["cut_over_uncut"])


if __name__ == "__main__":
    unittest.main()
