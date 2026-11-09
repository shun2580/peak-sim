<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Load;

use PeakSim\Runtime\Simulate\Load\LoadFailure;
use PeakSim\Runtime\Simulate\Load\ScenarioReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScenarioReaderTest extends TestCase
{
    private const HEAD = "schema_version: \"0.1\"\nresources:\n  db_cpu: { cores: 2 }\n";

    private static function scenario(string $body): string
    {
        return self::HEAD . "scenarios:\n" . $body;
    }

    private const CAL = "  - id: c1\n    kind: calibration\n    observed: { db_cpu_utilization: 0.2 }\n";

    public function test_the_form_of_the_spec_is_read_and_the_target_has_no_observed(): void
    {
        $r = ScenarioReader::parseStageOne(Fixtures::scenarioYaml());
        $this->assertSame(['cores' => 2, 'threshold' => 0.8, 'php_memory_limit_bytes' => 134217728], $r['resources']);
        $this->assertSame('calibration', $r['scenarios'][0]['kind']);
        $this->assertSame(0.21, $r['scenarios'][0]['observed']);
        $this->assertSame(['pages' => 1000, 'books' => 300], $r['scenarios'][0]['tables']);
        $this->assertSame(['GET /a' => 15.0, 'POST /m' => 6.0, 'GET /inc' => 2.0], $r['scenarios'][1]['rps'], 'an integer rps is a float');
        $this->assertArrayNotHasKey('observed', $r['scenarios'][1]);
    }

    public function test_the_threshold_defaults_to_0_8_and_the_memory_limit_is_optional(): void
    {
        $r = ScenarioReader::parseStageOne(self::scenario(self::CAL));
        $this->assertSame(0.8, $r['resources']['threshold']);
        $this->assertNull($r['resources']['php_memory_limit_bytes']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function memory(): iterable
    {
        yield '128M' => ['128M', 134217728];
        yield '1G' => ['1G', 1073741824];
        yield '512k' => ['512k', 524288];
        yield 'bytes as text' => ['268435456', 268435456];
    }

    #[DataProvider('memory')]
    public function test_the_memory_limit_sizes(string $given, int $bytes): void
    {
        $yaml = "schema_version: \"0.1\"\nresources:\n  db_cpu: { cores: 1 }\n  php_memory_limit: {$given}\nscenarios:\n" . self::CAL;
        $this->assertSame($bytes, ScenarioReader::parseStageOne($yaml)['resources']['php_memory_limit_bytes']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function stops(): iterable
    {
        yield 'no schema_version' => ["resources:\n  db_cpu: { cores: 2 }\nscenarios:\n" . self::CAL, LoadFailure::SCHEMA_VERSION];
        yield 'another schema_version' => ["schema_version: \"0.2\"\nresources:\n  db_cpu: { cores: 2 }\nscenarios:\n" . self::CAL, LoadFailure::SCHEMA_VERSION];
        yield 'cores missing' => ["schema_version: \"0.1\"\nresources:\n  db_cpu: { threshold: 0.8 }\nscenarios:\n" . self::CAL, LoadFailure::CORES_MISSING];
        yield 'no resources at all' => ["schema_version: \"0.1\"\nscenarios:\n" . self::CAL, LoadFailure::CORES_MISSING];
        yield 'cores is zero' => ["schema_version: \"0.1\"\nresources:\n  db_cpu: { cores: 0 }\nscenarios:\n" . self::CAL, LoadFailure::SCENARIO_INVALID];
        yield 'calibration without observed' => [self::scenario("  - id: c1\n    kind: calibration\n    rps: { \"GET /a\": 1 }\n"), LoadFailure::OBSERVED_MISSING];
        yield 'calibration with an observed that has no db_cpu_utilization' => [self::scenario("  - id: c1\n    kind: calibration\n    observed: { other: 1 }\n"), LoadFailure::OBSERVED_MISSING];
        yield 'not YAML of the small form' => [self::HEAD . "scenarios: &x\n" . self::CAL, LoadFailure::SCENARIO_INVALID];
        yield 'a top that is a list' => ["- a\n- b\n", LoadFailure::SCENARIO_INVALID];
        yield 'no scenarios' => [self::HEAD . "scenarios: []\n", LoadFailure::SCENARIO_INVALID];
        yield 'an unknown top key' => [self::scenario(self::CAL) . "extra: 1\n", LoadFailure::SCENARIO_INVALID];
        yield 'an unknown scenario key (a typo of tables)' => [self::scenario(self::CAL . "    table: { pages: 1 }\n"), LoadFailure::SCENARIO_INVALID];
        yield 'a kind that is not known' => [self::scenario("  - id: x\n    kind: forecast\n"), LoadFailure::SCENARIO_INVALID];
        yield 'the same id twice' => [self::scenario(self::CAL . self::CAL), LoadFailure::SCENARIO_INVALID];
        yield 'a table count that is negative' => [self::scenario(self::CAL . "    tables: { pages: -1 }\n"), LoadFailure::SCENARIO_INVALID];
        yield 'a table count that is not whole' => [self::scenario(self::CAL . "    tables: { pages: 1.5 }\n"), LoadFailure::SCENARIO_INVALID];
        yield 'a negative rps' => [self::scenario(self::CAL . "    rps: { \"GET /a\": -1 }\n"), LoadFailure::SCENARIO_INVALID];
        yield 'a threshold above 1' => ["schema_version: \"0.1\"\nresources:\n  db_cpu: { cores: 2, threshold: 1.5 }\nscenarios:\n" . self::CAL, LoadFailure::SCENARIO_INVALID];
        yield 'a memory limit that is not a size' => ["schema_version: \"0.1\"\nresources:\n  db_cpu: { cores: 2 }\n  php_memory_limit: lots\nscenarios:\n" . self::CAL, LoadFailure::SCENARIO_INVALID];
    }

    #[DataProvider('stops')]
    public function test_the_reading_stops_with_its_own_code(string $yaml, int $code): void
    {
        try {
            ScenarioReader::parseStageOne($yaml);
            $this->fail('the reading should have stopped');
        } catch (LoadFailure $e) {
            $this->assertSame($code, $e->exitCode, $e->getMessage());
        }
    }

    public function test_the_codes_are_the_ones_recorded(): void
    {
        $this->assertSame([20, 21, 22, 23, 24, 25], [LoadFailure::SCENARIO_INVALID, LoadFailure::SCHEMA_VERSION, LoadFailure::UNKNOWN_ROUTE, LoadFailure::OBSERVED_MISSING, LoadFailure::CORES_MISSING, LoadFailure::ESTIMATE_INVALID]);
    }

    public function test_the_second_stage_reads_the_target_observed_and_only_that(): void
    {
        $this->assertSame(['peak-1' => 0.74317], ScenarioReader::targetObserved(Fixtures::scenarioYaml()));
    }

    public function test_the_first_stage_does_not_even_parse_a_target_observed(): void
    {
        // an observed that the YAML reader would refuse: the first stage never sees it, so it goes through; the second stage, which parses it, stops
        $yaml = self::scenario(self::CAL . "  - id: t1\n    kind: target\n    observed: &junk { db_cpu_utilization: 0.74317\n");
        $this->assertSame('t1', ScenarioReader::parseStageOne($yaml)['scenarios'][1]['id']);
        $this->expectException(LoadFailure::class);
        ScenarioReader::targetObserved($yaml);
    }

    public function test_a_target_without_observed_is_simply_not_in_the_second_stage_answer(): void
    {
        $yaml = self::scenario(self::CAL . "  - id: t1\n    kind: target\n");
        $this->assertSame([], ScenarioReader::targetObserved($yaml));
    }
}
