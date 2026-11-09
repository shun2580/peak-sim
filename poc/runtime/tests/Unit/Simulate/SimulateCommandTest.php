<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate;

use PeakSim\Runtime\Simulate\Load\TwoStage;
use PeakSim\Runtime\Simulate\SimulateCommand;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\Fixtures;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\RecordingIo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SimulateCommandTest extends TestCase
{
    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function invoke(RecordingIo $io, array $args, ?callable $next = null): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new SimulateCommand($out, $err, $io, $next))->run($args);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return list<string> */
    private static function args(): array
    {
        return ['--estimate=/est', '--scenario=' . Fixtures::SCENARIO_PATH, '--out=/out'];
    }

    public function test_it_writes_the_loaded_input_and_says_what_it_found(): void
    {
        $io = Fixtures::io();
        [$code, $stdout] = self::invoke($io, self::args());
        $this->assertSame(0, $code);
        $this->assertArrayHasKey('/out/loaded.json', $io->files);
        $this->assertStringContainsString('2 scenario(s)', $stdout);
        $this->assertStringContainsString('peak-1 (target): 3 route(s) with rps, 2 with unknown cost, 1 unknown table(s) [books]', $stdout);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function wrongUsages(): iterable
    {
        yield 'nothing' => [[], '--estimate is required'];
        yield 'no scenario' => [['--estimate=/est', '--out=/o'], '--scenario is required'];
        yield 'an unknown argument' => [['--bogus'], 'unknown argument'];
    }

    /** @param list<string> $args */
    #[DataProvider('wrongUsages')]
    public function test_a_wrong_usage_is_exit_two_with_the_usage_text(array $args, string $message): void
    {
        [$code, $out, $err] = self::invoke(Fixtures::io(), $args);
        $this->assertSame(2, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString($message, $err);
        $this->assertStringContainsString('usage: bin/simulate', $err);
    }

    /** @return iterable<string, array{string, int}> */
    public static function stoppedInputs(): iterable
    {
        yield 'schema_version differs' => [str_replace('schema_version: "0.1"', 'schema_version: "9"', Fixtures::scenarioYaml()), 21];
        yield 'a route that is not there' => [str_replace('"GET /a": 5.0', '"GET /zzz": 5.0', Fixtures::scenarioYaml()), 22];
        yield 'a calibration without observed' => [str_replace("    observed: { db_cpu_utilization: 0.21 }\n", '', Fixtures::scenarioYaml()), 23];
        yield 'cores missing' => [str_replace("    cores: 2              # CPUs given to the database\n", '', Fixtures::scenarioYaml()), 24];
        yield 'a file that is not the form' => ["a: &x 1\n", 20];
    }

    #[DataProvider('stoppedInputs')]
    public function test_an_input_that_stops_the_run_gives_its_exit_code_and_writes_nothing(string $yaml, int $code): void
    {
        $io = Fixtures::io($yaml);
        [$got, , $err] = self::invoke($io, self::args());
        $this->assertSame($code, $got, $err);
        $this->assertStringStartsWith('bin/simulate: ', $err);
        $this->assertArrayNotHasKey('/out/loaded.json', $io->files);
    }

    public function test_the_next_step_gets_the_loaded_input_and_decides_the_exit_code(): void
    {
        $io = Fixtures::io();
        $seen = null;
        [$code] = self::invoke($io, self::args(), static function (TwoStage $stage, $input, string $out) use (&$seen): int {
            $seen = [count($input->scenarios), $out];
            $stage->writeOutput($out . '/prediction.json', '{}');

            return 7;
        });
        $this->assertSame(7, $code);
        $this->assertSame([2, '/out'], $seen);
        $this->assertArrayHasKey('/out/prediction.json', $io->files);
    }
}
