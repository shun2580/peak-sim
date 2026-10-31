<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance;

use PeakSim\Tests\Acceptance\Support\GapInjection;
use PeakSim\Tests\Acceptance\Support\MetamorphicScenarios;
use PeakSim\Tests\Acceptance\Support\SourceMutator;
use PeakSim\Tests\Support\ExpectationFixtureRows;
use PeakSim\Tests\Support\MiniAppEchoAnalyzer;
use PeakSim\Tests\Support\MiniAppWorkspace;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests of the metamorphic and gap-injection machinery. The tool is not involved: these are green today and say
 * that a red MetamorphicTest / GapInjectionTest means the tool is wrong, not the rewriting.
 */
final class MetamorphicSelfTest extends TestCase
{
    /** @var list<string> */
    private array $copies = [];

    protected function tearDown(): void
    {
        foreach ($this->copies as $copy) {
            MiniAppWorkspace::remove($copy);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function scenarioProvider(): array
    {
        return MetamorphicTest::scenarioProvider();
    }

    /** Every edit applies exactly once, really changes the file, and leaves valid PHP behind. */
    #[DataProvider('scenarioProvider')]
    public function testEveryScenarioApplies(string $name): void
    {
        $scenario = MetamorphicScenarios::all()[$name];
        $copy = $this->copies[] = MiniAppWorkspace::copyApp(MiniAppWorkspace::fixtureApp(), 'self');

        $written = MetamorphicScenarios::apply($scenario, $copy);

        $this->assertNotSame([], $written);
        foreach ($written as $file) {
            $this->assertParses(MiniAppWorkspace::read($copy, $file), $file);
            if (is_file(MiniAppWorkspace::fixtureApp() . '/' . $file)) {
                $this->assertNotSame(
                    file_get_contents(MiniAppWorkspace::fixtureApp() . '/' . $file),
                    MiniAppWorkspace::read($copy, $file),
                    "{$file}: the copy must differ from the fixture"
                );
            }
        }
    }

    /** The fixture itself is never touched. */
    public function testApplyingScenariosLeavesTheFixtureAlone(): void
    {
        $before = md5(implode('', array_map('md5_file', self::appFiles())));
        foreach (MetamorphicScenarios::all() as $scenario) {
            $copy = $this->copies[] = MiniAppWorkspace::copyApp(MiniAppWorkspace::fixtureApp(), 'self');
            MetamorphicScenarios::apply($scenario, $copy);
        }

        $this->assertSame($before, md5(implode('', array_map('md5_file', self::appFiles()))));
    }

    /** An 'unrelated' scenario must say 'same'; worse/better ones must name an axis or a check (no scenario is a no-op). */
    public function testEveryScenarioStatesWhatItExpects(): void
    {
        $strict = 0;
        foreach (MetamorphicScenarios::all() as $name => $s) {
            if ($s['kind'] === 'unrelated') {
                $this->assertTrue($s['same'] ?? false, "{$name}: an unrelated change must require the verdict to stay the same");
                continue;
            }
            $this->assertTrue(isset($s['axes']) || isset($s['check']), "{$name}: says nothing about the verdict");
            ++$strict;
        }
        $this->assertGreaterThanOrEqual(10, $strict, 'too few worsening / improving scenarios');
    }

    /** The dummy of design 3.2-3: an analyzer that only copies expected.json must be caught by at least six scenarios. */
    public function testAnAnalyzerThatOnlyCopiesExpectedJsonFailsAtLeastSixScenarios(): void
    {
        $failed = [];
        foreach (MetamorphicScenarios::all() as $name => $scenario) {
            if (MetamorphicScenarios::evaluate($scenario, new MiniAppEchoAnalyzer()) !== []) {
                $failed[] = $name;
            }
        }

        $this->assertGreaterThanOrEqual(6, count($failed), 'the copying dummy was caught by only: ' . implode('; ', $failed));
        fwrite(STDOUT, "\n[metamorphic] the expected.json-copying dummy fails " . count($failed) . ' of ' . count(MetamorphicScenarios::all()) . " scenarios\n");
    }

    /** The unrelated scenarios are exactly the ones the copying dummy cannot fail (otherwise they would test nothing about the tool). */
    public function testTheCopyingDummyPassesEveryUnrelatedScenario(): void
    {
        foreach (MetamorphicScenarios::all() as $name => $scenario) {
            if ($scenario['kind'] === 'unrelated') {
                $this->assertSame([], MetamorphicScenarios::evaluate($scenario, new MiniAppEchoAnalyzer()), $name);
            }
        }
    }

    // ---- gap injection ----

    public function testEveryExpectedRouteHasAnActionToInjectInto(): void
    {
        $this->assertSame(array_keys(ExpectationFixtureRows::rows()), array_keys(GapInjection::actions()), 'the 24 routes of expected.json must all be controller routes in the export');
    }

    public function testTheVariantsApplyToEnoughRoutes(): void
    {
        $this->assertCount(24, GapInjection::routesFor('start'));
        $this->assertGreaterThanOrEqual(20, count(GapInjection::routesFor('call')), 'routes with a call into the app to replace');
        $this->assertGreaterThanOrEqual(3, count(GapInjection::routesFor('deep')), 'routes with a callee below the action');
        foreach (['/ng/service', '/ng/layered', '/hidden/conditional-binding'] as $uri) {
            $this->assertContains($uri, GapInjection::routesFor('deep'), "{$uri} hides its query below the action: a deep variant is needed");
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function injectionProvider(): array
    {
        $cases = [];
        foreach (GapInjection::VARIANTS as $variant) {
            foreach (GapInjection::routesFor($variant) as $uri) {
                $cases["{$uri} [{$variant}]"] = [$uri, $variant];
            }
        }

        return $cases;
    }

    /** The copy parses, holds the unfollowable call exactly where intended, and the fixture is unchanged. */
    #[DataProvider('injectionProvider')]
    public function testEveryInjectionYieldsValidPhpWithTheUnfollowableCall(string $uri, string $variant): void
    {
        $edit = GapInjection::edit($uri, $variant);
        $this->assertNotNull($edit);
        $copy = $this->copies[] = GapInjection::copyWith($uri, $variant);

        $source = MiniAppWorkspace::read($copy, $edit['file']);
        $this->assertParses($source, $edit['file']);
        $this->assertSame(1, substr_count($source, 'vendor_unknown_fn'), "{$edit['file']}: the unfollowable call must appear exactly once");
        $this->assertStringNotContainsString('vendor_unknown_fn', (string) file_get_contents(MiniAppWorkspace::fixtureApp() . '/' . $edit['file']), 'the fixture must stay untouched');
        $this->assertStringStartsWith(sys_get_temp_dir(), $copy, 'the copy must live outside the repository');
        $this->assertDirectoryDoesNotExist($copy . '/vendor');
    }

    public function testTheMutatorFindsTheCallsItShouldInAKnownController(): void
    {
        $mutator = new SourceMutator(MiniAppWorkspace::fixtureApp());
        $service = 'App\\Http\\Controllers\\Ng\\DeepServiceController';

        $call = $mutator->replaceAppCall($service, '__invoke');
        $this->assertNotNull($call);
        $this->assertStringContainsString("['orders' => \\vendor_unknown_fn()]", $call['source']);

        $deep = $mutator->replaceDeepestCall($service, '__invoke');
        $this->assertNotNull($deep);
        $this->assertGreaterThanOrEqual(2, $deep['depth']);
        $this->assertNotSame($mutator->fileOf($service), $deep['file'], 'the deep variant must edit a callee, not the controller');
    }

    private function assertParses(string $source, string $file): void
    {
        if (!str_ends_with($file, '.php') || str_ends_with($file, '.blade.php')) {
            return;
        }
        try {
            (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        } catch (\PhpParser\Error $e) {
            $this->fail("{$file} does not parse after the edit: " . $e->getMessage());
        }
        $this->addToAssertionCount(1);
    }

    /** @return list<string> */
    private static function appFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(MiniAppWorkspace::fixtureApp() . '/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $out[] = $f->getPathname();
        }
        sort($out);

        return $out;
    }
}
