<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance;

use PeakSim\Tests\Acceptance\Support\GapInjection;
use PeakSim\Tests\Support\MiniAppAnalyzer;
use PeakSim\Tests\Support\MiniAppRealAnalyzer;
use PeakSim\Tests\Support\MiniAppWorkspace;
use PeakSim\Tests\Support\ReportAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Do not turn not-knowing into safe" (D21), on every route (design 3.2-2). In a copy of the fixture app a call on the
 * route's path is made unfollowable — a call to a function nobody defines — and the tool must answer unknown on both
 * axes with at least one gap. The copy lives in the system temp directory and is removed after each case.
 *
 * The three ways of making the call unfollowable are in GapInjection (start of the action, a call into the app inside
 * the action, the deepest reachable call).
 */
final class GapInjectionTest extends TestCase
{
    /** @var list<string> */
    private array $copies = [];

    protected function tearDown(): void
    {
        foreach ($this->copies as $copy) {
            MiniAppWorkspace::remove($copy);
        }
    }

    protected function analyzer(): MiniAppAnalyzer
    {
        return new MiniAppRealAnalyzer();
    }

    /** @return array<string, array{0: string}> */
    public static function startProvider(): array
    {
        return self::provide('start');
    }

    /** @return array<string, array{0: string}> */
    public static function callProvider(): array
    {
        return self::provide('call');
    }

    /** @return array<string, array{0: string}> */
    public static function deepProvider(): array
    {
        return self::provide('deep');
    }

    #[DataProvider('startProvider')]
    public function testAnUnfollowableCallAtTheStartOfTheActionMakesBothAxesUnknown(string $uri): void
    {
        $this->assertUnknownAfterInjection($uri, 'start');
    }

    #[DataProvider('callProvider')]
    public function testAnAppCallInTheActionReplacedByAnUnfollowableCallMakesBothAxesUnknown(string $uri): void
    {
        $this->assertUnknownAfterInjection($uri, 'call');
    }

    #[DataProvider('deepProvider')]
    public function testTheDeepestCallOnThePathReplacedByAnUnfollowableCallMakesBothAxesUnknown(string $uri): void
    {
        $this->assertUnknownAfterInjection($uri, 'deep');
    }

    private function assertUnknownAfterInjection(string $uri, string $variant): void
    {
        $copy = GapInjection::copyWith($uri, $variant);
        $this->copies[] = $copy;

        $view = $this->analyzer()->analyzeRoute($copy, MiniAppWorkspace::fixtureExport(), $uri);

        $this->assertSame('unknown', $view['query']['growth'], "{$uri} [{$variant}]: query must be unknown, not " . $view['query']['growth']);
        $this->assertSame('unknown', $view['memory']['growth'], "{$uri} [{$variant}]: memory must be unknown, not " . $view['memory']['growth']);
        $this->assertGreaterThanOrEqual(1, count($view['gaps']), "{$uri} [{$variant}]: an unknown verdict needs a gap that explains it");
        $this->assertSame([], ReportAssert::invariants($view), "{$uri} [{$variant}]");
    }

    /** @return array<string, array{0: string}> */
    private static function provide(string $variant): array
    {
        $cases = [];
        foreach (GapInjection::routesFor($variant) as $uri) {
            $cases[$uri] = [$uri];
        }

        return $cases;
    }
}
