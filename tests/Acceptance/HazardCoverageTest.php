<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance;

use PeakSim\Tests\Support\MiniAppWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The table of design 4.2 — the twelve ways a verdict can come out too light — against the mini-apps (3.2-5).
 * Every hazard is either covered by cases that exist, or declared as a known hole with its reason. A hazard that is
 * in neither list, or a case that has been renamed away, fails here. It does not need the tool.
 */
final class HazardCoverageTest extends TestCase
{
    /** @var array<string, array{what: string, cases: list<string>}> */
    private const COVERED = [
        'H1' => ['what' => 'allow-list too wide or too narrow', 'cases' => ['k-known-function', 'k-known-facade', 'k-unknown-facade-gap', 'k-gate-gap', 'k-validator-gap', 'k-dispatch-gap', 'e-unknown-never-without-gap']],
        'H2' => ['what' => 'an unknown made without a gap', 'cases' => ['e-unknown-never-without-gap', 'e-new-unknown-class-gap', 'e-dynamic-class-gap', 'bind-none-gap']],
        'H3' => ['what' => 'the origin of N lost when rows are repacked', 'cases' => ['q-pluck-insert', 'q-pluck-chunked-insert', 'q-upsert', 'q-create-many', 'c-array-map', 'c-stream-materialize']],
        'H4' => ['what' => 'memory of eager-loaded to-many relations', 'cases' => ['q-eager-to-many-memory', 'q-with-dotted', 'q-model-with-2']],
        'H6' => ['what' => 'accessors that hide a query (mutators are a declared hole, below)', 'cases' => ['r-appends-new-style', 'r-appends-old-style', 'r-appends-nested-relation', 'r-accessor-on-plain-read']],
        'H7' => ['what' => 'observers', 'cases' => ['q-observer-gap']],
        'H8' => ['what' => 'branches that may not run are still counted', 'cases' => ['e-union-if', 'e-union-ternary', 'blade-if-unless']],
        'H9' => ['what' => 'arrays whose size comes from the request', 'cases' => ['e-loop-source-unknown-gap']],
        'H10' => ['what' => 'generators, macros, __call', 'cases' => ['e-generator-gap', 'c-collection-macro-gap', 'q-static-unknown-method-gap']],
        'H11' => ['what' => 'raw SQL and unnamed tables', 'cases' => ['q-db-raw-select', 'q-db-table']],
        'H12' => ['what' => 'accumulating inside a stream', 'cases' => ['c-accumulate-in-chunk', 'c-accumulate-nested']],
    ];

    /** @var array<string, string> hazards with no mini-app, and why (these stay as "holes that remain" in the report) */
    private const DECLARED_HOLES = [
        'H5' => 'virtual calls through a subclass override: a P2 item of the design, absent from the fixtures; a mini-app would contradict the NO_BINDING rule for unbound abstract classes until P2 is decided',
    ];

    /** @return array<string, array{0: string}> */
    public static function hazardProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::COVERED) as $id) {
            $cases[$id] = [$id];
        }

        return $cases;
    }

    #[DataProvider('hazardProvider')]
    public function testEveryCoveringCaseExists(string $hazard): void
    {
        $missing = [];
        foreach (self::COVERED[$hazard]['cases'] as $case) {
            if (!is_file(MiniAppWorkspace::appsDir() . '/' . $case . '/expect.php')) {
                $missing[] = $case;
            }
        }

        $this->assertSame([], $missing, $hazard . ' (' . self::COVERED[$hazard]['what'] . '): cases that do not exist');
    }

    public function testAllTwelveHazardsAreCoveredOrDeclared(): void
    {
        $all = array_map(static fn (int $n): string => 'H' . $n, range(1, 12));
        $known = array_merge(array_keys(self::COVERED), array_keys(self::DECLARED_HOLES));
        sort($known);
        sort($all);

        $this->assertSame($all, $known, 'every hazard of design 4.2 must be covered or declared');
        $this->assertSame([], array_intersect(array_keys(self::COVERED), array_keys(self::DECLARED_HOLES)), 'a hazard cannot be both');
        foreach (self::DECLARED_HOLES as $id => $reason) {
            $this->assertNotSame('', trim($reason), "{$id}: a declared hole needs its reason");
        }
    }
}
