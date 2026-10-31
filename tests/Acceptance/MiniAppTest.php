<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance;

use PeakSim\Tests\Support\MiniAppAnalyzer;
use PeakSim\Tests\Support\MiniAppCase;
use PeakSim\Tests\Support\MiniAppRealAnalyzer;
use PeakSim\Tests\Support\MiniAppRunner;
use PeakSim\Tests\Support\MiniAppWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Data-driven: every directory of tests/Fixtures/Apps is one case. Adding a case means adding a directory
 * (expect.php, export.php, SPEC.md and the app). Nothing in this class names a case.
 */
final class MiniAppTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function caseProvider(): array
    {
        $cases = [];
        foreach (MiniAppCase::discover(MiniAppWorkspace::appsDir()) as $case) {
            $cases[$case->name] = [$case->name];
        }

        return $cases;
    }

    protected function analyzer(): MiniAppAnalyzer
    {
        return new MiniAppRealAnalyzer();
    }

    #[DataProvider('caseProvider')]
    public function testCaseMatchesItsExpectation(string $name): void
    {
        $case = null;
        foreach (MiniAppCase::discover(MiniAppWorkspace::appsDir()) as $candidate) {
            if ($candidate->name === $name) {
                $case = $candidate;
            }
        }
        $this->assertNotNull($case, "case {$name} disappeared");

        $problems = (new MiniAppRunner($this->analyzer()))->verify($case);

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
