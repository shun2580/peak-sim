<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Load;

use PeakSim\Runtime\Simulate\Load\LoadFailure;
use PeakSim\Runtime\Simulate\Load\StageOneText;
use PHPUnit\Framework\TestCase;

/** The first stage never parses a target's observed: it is cut out of the text before the parser sees it. */
final class StageOneTextTest extends TestCase
{
    public function test_a_target_observed_is_cut_out_and_a_calibration_observed_stays(): void
    {
        $out = StageOneText::withoutTargetObserved(Fixtures::scenarioYaml());
        $this->assertStringNotContainsString(Fixtures::TARGET_OBSERVED, $out);
        $this->assertStringContainsString('observed: { db_cpu_utilization: 0.21 }', $out);
        $this->assertStringContainsString('rps: { "GET /a": 15.0, "POST /m": 6.0, "GET /inc": 2 }', $out, 'the rest of the target is untouched');
    }

    public function test_a_block_form_observed_goes_with_its_lines_and_the_next_key_stays(): void
    {
        $yaml = <<<'YAML'
            schema_version: "0.1"
            scenarios:
              - id: t
                kind: target
                observed:
                  # a comment
                  db_cpu_utilization: 0.74317

                rps: { "GET /a": 1 }
              - id: c
                kind: calibration
                observed:
                  db_cpu_utilization: 0.5
            YAML;
        $out = StageOneText::withoutTargetObserved($yaml);
        $this->assertStringNotContainsString('0.74317', $out);
        $this->assertStringNotContainsString('a comment', $out);
        $this->assertStringContainsString('rps: { "GET /a": 1 }', $out);
        $this->assertStringContainsString('db_cpu_utilization: 0.5', $out, 'a calibration keeps its observed');
    }

    public function test_the_kind_may_come_after_the_observed(): void
    {
        $yaml = "scenarios:\n  - id: t\n    observed: { db_cpu_utilization: 0.74317 }\n    kind: target\n";
        $this->assertStringNotContainsString('0.74317', StageOneText::withoutTargetObserved($yaml));
    }

    public function test_a_text_after_the_scenarios_is_not_touched(): void
    {
        $yaml = "scenarios:\n  - id: t\n    kind: target\n    observed: { db_cpu_utilization: 0.74317 }\nnote: observed: kept\n";
        $out = StageOneText::withoutTargetObserved($yaml);
        $this->assertStringContainsString('note: observed: kept', $out);
        $this->assertStringNotContainsString('0.74317', $out);
    }

    public function test_an_observed_as_the_first_key_of_an_item_is_refused_not_missed(): void
    {
        $this->expectException(LoadFailure::class);
        StageOneText::withoutTargetObserved("scenarios:\n  - observed: { db_cpu_utilization: 0.74317 }\n    id: t\n    kind: target\n");
    }
}
