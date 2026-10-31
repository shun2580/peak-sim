<?php

declare(strict_types=1);

namespace PeakSim\Tests\Prototype;

use PeakSim\Prototype\Analyzer;
use PeakSim\Prototype\RouteAnalysis;
use PHPUnit\Framework\TestCase;

/**
 * Metamorphic tests: copy the fixture app to a temporary directory, rewrite a few lines of the
 * copy's source, and check that the verdict moves the way the rewrite says it should.
 *
 * A prototype that only echoes fixtures/expected.json would give the same answer for the original
 * and for every rewritten copy, so these tests fail for it. Each rewrite asserts that it actually
 * changed the file, so a pattern that no longer matches cannot turn the test green by accident.
 */
final class MetamorphicTest extends TestCase
{
    private const TARGET_ROUTES = ['/ng/n-plus-one', '/ok/eager', '/ng/layered'];

    /** Top-level entries of the fixture app that the analysis never reads and that are large. */
    private const SKIPPED = ['vendor', 'node_modules', 'storage', '.env'];

    private const CONFIDENCE_RANK = ['low' => 0, 'medium' => 1, 'high' => 2];

    private const REPOSITORY = 'app/Ng/Repositories/EloquentOrderRepository.php';

    private const PROVIDER = 'app/Ng/Providers/LayeredServiceProvider.php';

    private string $copyRoot = '';

    protected function setUp(): void
    {
        $this->copyRoot = sys_get_temp_dir() . '/peak-sim-meta-' . bin2hex(random_bytes(8));
        self::copyTree(self::appRoot(), $this->copyRoot);
    }

    protected function tearDown(): void
    {
        if ($this->copyRoot !== '') {
            self::removeTree($this->copyRoot);
        }
    }

    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function appRoot(): string
    {
        return self::projectRoot() . '/fixtures/laravel-app';
    }

    private static function exportDir(): string
    {
        return self::projectRoot() . '/fixtures/artisan-export';
    }

    private function original(string $route): RouteAnalysis
    {
        return (new Analyzer(self::appRoot(), self::exportDir()))->analyzeRoute($route);
    }

    private function analyzeCopy(string $route): RouteAnalysis
    {
        return (new Analyzer($this->copyRoot, self::exportDir()))->analyzeRoute($route);
    }

    // (d) control: copying alone must not change any verdict.

    /** @return array<string, array{0: string}> */
    public static function targetRouteProvider(): array
    {
        $cases = [];
        foreach (self::TARGET_ROUTES as $route) {
            $cases[$route] = [$route];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('targetRouteProvider')]
    public function testUnmodifiedCopyGivesTheSameVerdictAsTheOriginal(string $route): void
    {
        $this->assertSame(
            serialize($this->original($route)),
            serialize($this->analyzeCopy($route)),
            "{$route}: an untouched copy of the app must be judged exactly like the original"
        );
    }

    // (a) removing with() from /ok/eager makes it a lazy-load loop.

    public function testRemovingEagerLoadingMakesOkEagerLinearInQueries(): void
    {
        $before = $this->original('/ok/eager');
        $this->assertSame('O(1)', $before->query, 'precondition: /ok/eager is O(1) in queries');

        $this->rewrite(
            'app/Http/Controllers/Ok/OrderController.php',
            "Order::with('customer')->get()",
            'Order::get()'
        );
        $after = $this->analyzeCopy('/ok/eager');

        $this->assertSame('O(N)', $after->query, '/ok/eager without with(): reading ->customer per order is O(N) queries');
        $this->assertSame($before->memory, $after->memory, 'dropping with() does not change how many rows are loaded');
        $this->assertSame($before->nTable, $after->nTable, 'dropping with() does not change the growing table');
        $this->assertEvidenceIsReal($after, '/ok/eager');
    }

    // (b) replacing the relation read in /ng/n-plus-one's loop removes the N+1.

    public function testReplacingTheRelationReadMakesNPlusOneRouteConstantInQueries(): void
    {
        $before = $this->original('/ng/n-plus-one');
        $this->assertSame('O(N)', $before->query, 'precondition: /ng/n-plus-one is O(N) in queries');

        $this->rewrite(
            'app/Http/Controllers/Ng/BasicNPlusOneController.php',
            '$order->customer->name',
            '$order->total'
        );
        $after = $this->analyzeCopy('/ng/n-plus-one');

        $this->assertSame('O(1)', $after->query, '/ng/n-plus-one without the relation read in the loop: one query');
        $this->assertSame($before->memory, $after->memory, 'the route still loads every order');
        $this->assertSame($before->nTable, $after->nTable, 'the growing table is still orders');
        $this->assertEvidenceIsReal($after, '/ng/n-plus-one');
    }

    // (c) the verdict for /ng/layered must follow the ServiceProvider binding.

    public function testRebindingToAnotherLazyImplementationMovesTheEvidenceToIt(): void
    {
        $before = $this->original('/ng/layered');
        $this->assertSame('O(N)', $before->query, 'precondition: /ng/layered is O(N) in queries');
        $this->assertContains(self::REPOSITORY, self::files($before), 'precondition: evidence names the bound implementation');

        // A second implementation with the same planted N+1, in a file the old evidence cannot match.
        $source = $this->read(self::REPOSITORY);
        $alt = str_replace('class EloquentOrderRepository ', 'class LegacyOrderRepository ', $source);
        $this->assertNotSame($source, $alt, 'the copied implementation must be renamed');
        $this->write('app/Ng/Repositories/LegacyOrderRepository.php', $alt);
        $this->rebindTo('LegacyOrderRepository');
        $after = $this->analyzeCopy('/ng/layered');

        $files = self::files($after);
        $this->assertSame('O(N)', $after->query, 'the other implementation has the same N+1');
        $this->assertContains(
            'app/Ng/Repositories/LegacyOrderRepository.php',
            $files,
            '/ng/layered: the innermost query site is now in the newly bound class'
        );
        $this->assertNotContains(
            self::REPOSITORY,
            $files,
            '/ng/layered: the class that is no longer bound must not be named as the query site'
        );
        $this->assertEvidenceIsReal($after, '/ng/layered');
    }

    public function testRebindingToAnEagerImplementationRemovesTheNPlusOne(): void
    {
        $source = $this->read(self::REPOSITORY);
        $eager = str_replace(
            ['class EloquentOrderRepository ', 'Order::all()'],
            ['class EagerOrderRepository ', "Order::with('customer')->get()"],
            $source
        );
        $this->assertStringContainsString("Order::with('customer')->get()", $eager, 'the eager implementation must load customer up front');
        $this->write('app/Ng/Repositories/EagerOrderRepository.php', $eager);
        $this->rebindTo('EagerOrderRepository');
        $after = $this->analyzeCopy('/ng/layered');

        $this->assertSame(
            'O(1)',
            $after->query,
            '/ng/layered bound to an implementation that eager-loads customer: queries are O(1)'
        );
        $this->assertNotContains(
            self::REPOSITORY,
            self::files($after),
            '/ng/layered: the unbound lazy implementation must not be named as evidence'
        );
        $this->assertEvidenceIsReal($after, '/ng/layered');
    }

    public function testRemovingTheBindingStopsTheVerdictFromPointingAtTheImplementation(): void
    {
        $before = $this->original('/ng/layered');

        $this->rewrite(
            self::PROVIDER,
            '$this->app->bind(OrderRepository::class, EloquentOrderRepository::class);',
            '// binding removed'
        );
        $after = $this->analyzeCopy('/ng/layered');

        $stillNamesImplementation = in_array(self::REPOSITORY, self::files($after), true);
        $lessConfident = self::CONFIDENCE_RANK[$after->confidence] < self::CONFIDENCE_RANK[$before->confidence];
        $this->assertTrue(
            !$stillNamesImplementation || $lessConfident || $after->query === 'unknown',
            '/ng/layered without a binding: the interface cannot be resolved, so the verdict must either stop naming '
            . 'the implementation, lose confidence, or become unknown'
        );
        $this->assertEvidenceIsReal($after, '/ng/layered');
    }

    /** Points the interface binding (the import and the bind() call) at another class of App\\Ng\\Repositories. */
    private function rebindTo(string $class): void
    {
        $this->rewrite(
            self::PROVIDER,
            'use App\\Ng\\Repositories\\EloquentOrderRepository;',
            "use App\\Ng\\Repositories\\{$class};"
        );
        $this->rewrite(
            self::PROVIDER,
            'OrderRepository::class, EloquentOrderRepository::class',
            "OrderRepository::class, {$class}::class"
        );
    }

    /**
     * Replaces $search by $replace in a file of the copy, requiring exactly one occurrence and a real change.
     */
    private function rewrite(string $relative, string $search, string $replace): void
    {
        $before = $this->read($relative);
        $this->assertSame(
            1,
            substr_count($before, $search),
            "{$relative}: the text to rewrite must occur exactly once (fixture changed?)"
        );
        $after = str_replace($search, $replace, $before, $count);
        $this->assertSame(1, $count, "{$relative}: the rewrite must take effect");
        $this->assertNotSame($before, $after, "{$relative}: the rewritten file must differ from the original");
        $this->write($relative, $after);
        $this->assertNotSame(
            file_get_contents(self::appRoot() . '/' . $relative),
            file_get_contents($this->copyRoot . '/' . $relative),
            "{$relative}: the copy must now differ from the fixture"
        );
    }

    private function read(string $relative): string
    {
        $content = file_get_contents($this->copyRoot . '/' . $relative);
        $this->assertNotFalse($content, "cannot read {$relative} in the copy");

        return $content;
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->copyRoot . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $this->assertNotFalse(file_put_contents($path, $content), "cannot write {$relative} in the copy");
    }

    /** @return list<string> */
    private static function files(RouteAnalysis $analysis): array
    {
        return array_values(array_map(static fn (array $item): string => $item['file'], $analysis->evidence));
    }

    /** Evidence must stay inside the copy: an existing file, and a line that exists in it. */
    private function assertEvidenceIsReal(RouteAnalysis $analysis, string $route): void
    {
        $this->assertNotEmpty($analysis->evidence, "{$route}: evidence must not be empty after the rewrite");
        foreach ($analysis->evidence as $i => $item) {
            $path = $this->copyRoot . '/' . $item['file'];
            $this->assertFileExists($path, "{$route}: evidence[{$i}].file must exist in the rewritten app");
            $this->assertGreaterThanOrEqual(1, $item['line'], "{$route}: evidence[{$i}].line must be 1 or greater");
            $this->assertLessThanOrEqual(
                count(file($path)),
                $item['line'],
                "{$route}: evidence[{$i}].line must not be beyond the end of {$item['file']}"
            );
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::SKIPPED, true)) {
                continue;
            }
            $source = $from . '/' . $entry;
            if (is_dir($source)) {
                self::copyTree($source, $to . '/' . $entry);
            } else {
                copy($source, $to . '/' . $entry);
            }
        }
    }

    private static function removeTree(string $path): void
    {
        // Only ever delete what this test created.
        if (!str_starts_with($path, sys_get_temp_dir() . '/peak-sim-meta-') || !is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
