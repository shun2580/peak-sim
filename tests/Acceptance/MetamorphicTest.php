<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance;

use PeakSim\Tests\Acceptance\Support\MetamorphicScenarios;
use PeakSim\Tests\Support\MiniAppAnalyzer;
use PeakSim\Tests\Support\MiniAppRealAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Metamorphic v2 (design 3.2-3). In a copy of the fixture app the source is changed and the verdict must move the
 * way the change says: a worsening never lowers the axis it names, an improvement never raises it, an unrelated
 * change leaves the whole verdict as it was. The scenarios are data (Support/MetamorphicScenarios); the harness
 * proves in MetamorphicSelfTest that every edit applies and that a tool copying expected.json fails at least six.
 */
final class MetamorphicTest extends TestCase
{
    protected function analyzer(): MiniAppAnalyzer
    {
        return new MiniAppRealAnalyzer();
    }

    /** @return array<string, array{0: string}> */
    public static function scenarioProvider(): array
    {
        $cases = [];
        foreach (MetamorphicScenarios::all() as $name => $scenario) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    #[DataProvider('scenarioProvider')]
    public function testTheVerdictMovesAsTheChangeSays(string $name): void
    {
        $problems = MetamorphicScenarios::evaluate(MetamorphicScenarios::all()[$name], $this->analyzer());

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
