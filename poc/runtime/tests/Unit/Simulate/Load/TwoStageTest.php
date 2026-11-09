<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Load;

use PeakSim\Runtime\Simulate\Load\OrderViolation;
use PeakSim\Runtime\Simulate\Load\TwoStage;
use PHPUnit\Framework\TestCase;

/**
 * The order of section 3.4: a target's `observed` is read only after the output it must not come before is written. Held by the shape of the
 * code (the first stage cuts it out of the text and has no way to ask for it) and by these tests (taking the guard out makes them fail).
 */
final class TwoStageTest extends TestCase
{
    private const OUT = '/out';

    private const PREDICTION = '/out/prediction.json';

    public function test_the_scenario_file_is_read_again_for_observed_only_after_the_outputs_are_written(): void
    {
        $io = Fixtures::io();
        $stage = new TwoStage($io);
        $stage->stageOne('/est', Fixtures::SCENARIO_PATH, self::OUT);
        $stage->writeOutput(self::PREDICTION, '{"prediction":true}');
        $observed = $stage->stageTwo(Fixtures::SCENARIO_PATH, self::PREDICTION);

        $this->assertSame(['peak-1' => 0.74317], $observed);
        $this->assertSame([
            'read ' . Fixtures::SCENARIO_PATH,        // the first reading (the target observed is cut out of what is parsed)
            'read /est/estimate.json',
            'write /out/loaded.json',                  // the first stage's output
            'write ' . self::PREDICTION,               // the prediction
            'read ' . Fixtures::SCENARIO_PATH,        // only now the second reading, which takes the observed
        ], $io->events);
    }

    public function test_the_first_stage_output_does_not_hold_a_target_observed(): void
    {
        $io = Fixtures::io();
        (new TwoStage($io))->stageOne('/est', Fixtures::SCENARIO_PATH, self::OUT);
        $this->assertStringNotContainsString(Fixtures::TARGET_OBSERVED, $io->files['/out/loaded.json']);
        $this->assertStringContainsString('0.21', $io->files['/out/loaded.json'], 'the calibration observed is there');
    }

    public function test_the_second_stage_before_the_first_is_refused_and_reads_nothing(): void
    {
        $io = Fixtures::io();
        $stage = new TwoStage($io);
        try {
            $stage->stageTwo(Fixtures::SCENARIO_PATH);
            $this->fail('the order should have been refused');
        } catch (OrderViolation) {
            $this->assertSame([], $io->events, 'the scenario file was not touched');
        }
    }

    public function test_the_second_stage_before_the_prediction_is_written_is_refused_and_reads_nothing_more(): void
    {
        $io = Fixtures::io();
        $stage = new TwoStage($io);
        $stage->stageOne('/est', Fixtures::SCENARIO_PATH, self::OUT);
        $before = $io->events;
        try {
            $stage->stageTwo(Fixtures::SCENARIO_PATH, self::PREDICTION);
            $this->fail('the order should have been refused');
        } catch (OrderViolation $e) {
            $this->assertStringContainsString(self::PREDICTION, $e->getMessage());
            $this->assertSame($before, $io->events, 'the scenario file was not read a second time');
        }
    }

    public function test_an_output_written_around_the_guard_does_not_count(): void
    {
        $io = Fixtures::io();
        $stage = new TwoStage($io);
        $stage->stageOne('/est', Fixtures::SCENARIO_PATH, self::OUT);
        $io->write(self::PREDICTION, 'written by another route'); // not through TwoStage::writeOutput
        $this->expectException(OrderViolation::class);
        $stage->stageTwo(Fixtures::SCENARIO_PATH, self::PREDICTION);
    }

    public function test_an_output_that_was_written_and_is_gone_does_not_count(): void
    {
        $io = Fixtures::io();
        $stage = new TwoStage($io);
        $stage->stageOne('/est', Fixtures::SCENARIO_PATH, self::OUT);
        $stage->writeOutput(self::PREDICTION, 'x');
        unset($io->files[self::PREDICTION]);
        $this->expectException(OrderViolation::class);
        $stage->stageTwo(Fixtures::SCENARIO_PATH, self::PREDICTION);
    }

    public function test_the_first_stage_code_has_no_way_to_ask_for_the_observed(): void
    {
        $root = dirname(__DIR__, 4) . '/src/Simulate';
        $loader = (string) file_get_contents($root . '/Load/InputLoader.php');
        $this->assertStringNotContainsString('targetObserved', $loader);
        $this->assertStringNotContainsString('stageTwo', $loader);
        $this->assertStringContainsString('parseStageOne', $loader);
        $reader = (string) file_get_contents($root . '/Load/ScenarioReader.php');
        $this->assertSame(1, substr_count($reader, 'self::parseYaml($text)'), 'the whole text is parsed in one place only: the second stage');
        $this->assertStringContainsString('StageOneText::withoutTargetObserved($text)', $reader);
    }
}
