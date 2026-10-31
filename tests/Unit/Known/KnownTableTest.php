<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Known;

use PeakSim\Analysis\Handlers\Cache\CacheOps;
use PeakSim\Analysis\Handlers\Known\KnownEntry;
use PeakSim\Analysis\Handlers\Known\KnownTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The allow-list file itself: what it says, that every line carries its reason, that it loads the same every time. */
final class KnownTableTest extends TestCase
{
    private const FILE = __DIR__ . '/../../../resources/known-calls.php';

    /** What the 24 routes need, and nothing else. A new answered entry must be added here on purpose. */
    private const ANSWERED = [
        'function:abort_unless',
        'function:cache',
        'function:count',
        'function:in_array',
        'function:now',
        'function:response',
        'function:round',
        'method:illuminate\\http\\request::query',
        'static:illuminate\\database\\eloquent\\casts\\attribute::get',
        'static:illuminate\\database\\eloquent\\casts\\attribute::make',
    ];

    public function testTheDefaultTableIsTheFileUnderResources(): void
    {
        $this->assertFileExists(self::FILE);
        $this->assertEquals(KnownTable::fromFile(self::FILE), KnownTable::default());
    }

    public function testOnlyTheMinimumTheRoutesNeedIsAnswered(): void
    {
        $answered = array_keys(array_filter(KnownTable::default()->all(), static fn (KnownEntry $e): bool => !$e->isDelegated()));

        $this->assertSame(self::ANSWERED, $answered);
    }

    public function testEveryEntryStatesItsBasisAndItsReason(): void
    {
        foreach (KnownTable::default()->all() as $key => $entry) {
            $this->assertGreaterThan(30, strlen($entry->why), "{$key} needs a reason of its own");
            if (!$entry->isDelegated()) {
                $this->assertContains($entry->basis, ['verified', 'contract'], "{$key} must say how it was checked");
            }
        }
    }

    public function testEveryRefusedMouthHasAReason(): void
    {
        foreach (KnownTable::default()->refusals() as $i => $refusal) {
            $this->assertGreaterThan(30, strlen($refusal['why']), "refusal #{$i}");
            $this->assertTrue(isset($refusal['function']) || isset($refusal['class']), "refusal #{$i} names nothing");
        }
    }

    /** @return array<string, array{0: string, 1: string|null, 2: string}> kind, class, name */
    public static function refusedMouths(): array
    {
        $facades = 'Illuminate\\Support\\Facades\\';

        return [
            'gate facade' => ['method', $facades . 'Gate', 'authorize'],
            'gate contract' => ['method', 'Illuminate\\Contracts\\Auth\\Access\\Gate', 'allows'],
            'validator facade' => ['method', $facades . 'Validator', 'make'],
            'request validate' => ['method', 'Illuminate\\Http\\Request', 'validate'],
            'request user' => ['method', 'Illuminate\\Http\\Request', 'user'],
            'validator helper' => ['function', null, 'validator'],
            'event helper' => ['function', null, 'event'],
            'dispatch helper' => ['function', null, 'dispatch'],
            'event facade' => ['method', $facades . 'Event', 'dispatch'],
            'bus facade' => ['method', $facades . 'Bus', 'dispatch'],
            'auth facade' => ['method', $facades . 'Auth', 'user'],
            'auth helper' => ['function', null, 'auth'],
            'mail facade' => ['method', $facades . 'Mail', 'send'],
            'notification facade' => ['method', $facades . 'Notification', 'send'],
            'http facade' => ['method', $facades . 'Http', 'get'],
            'db raw' => ['method', $facades . 'DB', 'raw'],
            'app helper' => ['function', null, 'app'],
            'resolve helper' => ['function', null, 'resolve'],
            'app facade' => ['method', $facades . 'App', 'make'],
        ];
    }

    #[DataProvider('refusedMouths')]
    public function testTheMouthsThatCallBackAreRefusedWithAReason(string $kind, ?string $class, string $name): void
    {
        $table = KnownTable::default();
        $why = $kind === 'function' ? $table->refusedFunction($name) : $table->refusedMethod((string) $class, $name);

        $this->assertNotNull($why);
        $this->assertNotSame('', $why);
    }

    public function testRefusalsDoNotDependOnCase(): void
    {
        $table = KnownTable::default();

        $this->assertNotNull($table->refusedFunction('EVENT'));
        $this->assertNotNull($table->refusedMethod('illuminate\\support\\facades\\GATE', 'AUTHORIZE'));
    }

    public function testNothingRefusedIsAlsoListed(): void
    {
        $table = KnownTable::default();
        foreach ($table->all() as $key => $entry) {
            [$kind, $rest] = explode(':', $key, 2);
            if ($kind === 'function') {
                $this->assertNull($table->refusedFunction($rest), $key);
            } else {
                [$class, $method] = explode('::', $rest, 2);
                $this->assertNull($table->refusedMethod($class, $method), $key);
            }
        }
    }

    public function testWhatOtherHandlersOwnIsDelegatedByName(): void
    {
        $table = KnownTable::default();
        $response = 'Illuminate\\Contracts\\Routing\\ResponseFactory';

        $this->assertSame('ViewHandler', $table->forFunction('view')?->delegate);
        $this->assertSame('ResponseSink', $table->forMethod($response, 'json')?->delegate);
        $this->assertSame('ResponseSink', $table->forMethod('Illuminate\\Routing\\ResponseFactory', 'json')?->delegate);
        $this->assertSame('ViewHandler', $table->forMethod($response, 'view')?->delegate);
    }

    public function testTheCacheFamilyIsDelegatedToCacheOpsAndOnlyThat(): void
    {
        $table = KnownTable::default();
        $delegated = [];
        foreach ($table->all() as $key => $entry) {
            if ($entry->delegate === 'CacheOps') {
                $delegated[] = $key;
            }
        }

        $expected = [];
        foreach (array_keys(CacheOps::METHODS) as $method) {
            foreach ([CacheOps::FACADE, 'Cache', ...CacheOps::RECEIVERS] as $class) {
                $expected[] = ($class === CacheOps::FACADE || $class === 'Cache' ? 'static:' : 'method:') . strtolower($class) . '::' . $method;
            }
        }
        sort($expected);
        sort($delegated);
        $this->assertSame($expected, $delegated);
    }

    public function testTheAnsweredShapesOfTheMinimumSet(): void
    {
        $table = KnownTable::default();

        $this->assertSame('object:' . CacheOps::RECEIVERS[0], $table->forFunction('cache')?->returns);
        $this->assertSame([0, 0], [$table->forFunction('cache')?->argsMin, $table->forFunction('cache')?->argsMax]);
        $this->assertSame('object:Illuminate\\Contracts\\Routing\\ResponseFactory', $table->forFunction('response')?->returns);
        $this->assertSame(0, $table->forFunction('response')?->argsMax);
        $this->assertSame('plain', $table->forFunction('now')?->returns);
        $this->assertSame('arg:0:get', $table->forStatic('Illuminate\\Database\\Eloquent\\Casts\\Attribute', 'get')?->returns);
        $this->assertSame('arg:0:get', $table->forStatic('Illuminate\\Database\\Eloquent\\Casts\\Attribute', 'make')?->returns);
        $this->assertSame([0, 2], [$table->forStatic('Illuminate\\Database\\Eloquent\\Casts\\Attribute', 'make')?->argsMin, $table->forStatic('Illuminate\\Database\\Eloquent\\Casts\\Attribute', 'make')?->argsMax]);
        $this->assertTrue($table->forStatic('Illuminate\\Database\\Eloquent\\Casts\\Attribute', 'make')?->storesClosures ?? false);
        $this->assertTrue($table->forStatic('Illuminate\\Database\\Eloquent\\Casts\\Attribute', 'get')?->storesClosures ?? false);
        $this->assertFalse($table->forFunction('round')?->storesClosures ?? true);
        $this->assertSame('plain', $table->forMethod('Illuminate\\Http\\Request', 'query')?->returns);
    }

    public function testLookupsIgnoreCaseAndALeadingBackslash(): void
    {
        $table = KnownTable::default();

        $this->assertNotNull($table->forFunction('NOW'));
        $this->assertNotNull($table->forMethod('\\Illuminate\\Http\\REQUEST', 'Query'));
        $this->assertNull($table->forFunction('strlen'));
    }

    public function testTheFileCarriesNoAbsolutePathAndLoadsTheSameEveryTime(): void
    {
        $this->assertSame(0, preg_match_all('#/home/|/Users/|[A-Z]:\\\\#', (string) file_get_contents(self::FILE)));
        $this->assertEquals(KnownTable::fromFile(self::FILE), KnownTable::fromFile(self::FILE));
        $keys = array_keys(KnownTable::default()->all());
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    public function testTheOrderOfTheFileDoesNotChangeTheTable(): void
    {
        $a = KnownTable::fromArray([
            'functions' => [
                'zeta' => self::answer(),
                'alpha' => self::answer(),
            ],
        ]);
        $b = KnownTable::fromArray([
            'functions' => [
                'alpha' => self::answer(),
                'zeta' => self::answer(),
            ],
        ]);

        $this->assertSame(['function:alpha', 'function:zeta'], array_keys($a->all()));
        $this->assertEquals($a, $b);
    }

    public function testAWildcardAnswersEveryMethodOfAClassButNotAFunction(): void
    {
        $table = KnownTable::fromArray([
            'methods' => ['Vendor\\Pure' => ['*' => self::answer()]],
        ]);

        $this->assertSame('plain', $table->forMethod('Vendor\\Pure', 'anything')?->returns);
        $this->assertNull($table->forMethod('Vendor\\Other', 'anything'));
        $this->assertNull($table->forFunction('anything'));
    }

    public function testAnExactNameBeatsTheWildcard(): void
    {
        $table = KnownTable::fromArray([
            'methods' => ['Vendor\\Pure' => [
                '*' => self::answer(),
                'special' => ['delegate' => 'SomeHandler', 'why' => 'This one is somebody else\'s to follow.'],
            ]],
        ]);

        $this->assertTrue($table->forMethod('Vendor\\Pure', 'special')?->isDelegated() ?? false);
        $this->assertFalse($table->forMethod('Vendor\\Pure', 'other')?->isDelegated() ?? true);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badTables(): array
    {
        $ok = self::answer();

        return [
            'no reason' => [['functions' => ['f' => array_diff_key($ok, ['why' => 1])]], 'why'],
            'empty reason' => [['functions' => ['f' => ['why' => '  '] + $ok]], 'why'],
            'no basis' => [['functions' => ['f' => array_diff_key($ok, ['basis' => 1])]], 'basis'],
            'unknown basis' => [['functions' => ['f' => ['basis' => 'guess'] + $ok]], 'basis'],
            'unknown return' => [['functions' => ['f' => ['returns' => 'int'] + $ok]], 'returns'],
            'object without class' => [['functions' => ['f' => ['returns' => 'object:'] + $ok]], 'returns'],
            'arg without index' => [['functions' => ['f' => ['returns' => 'arg:x'] + $ok]], 'returns'],
            'arg name that is not a name' => [['functions' => ['f' => ['returns' => 'arg:0:1x', 'args' => [1, 2]] + $ok]], 'returns'],
            'arg beyond the arity' => [['functions' => ['f' => ['returns' => 'arg:3', 'args' => [1, 2]] + $ok]], 'returns'],
            'min above max' => [['functions' => ['f' => ['args' => [3, 1]] + $ok]], 'args'],
            'negative min' => [['functions' => ['f' => ['args' => [-1, 1]] + $ok]], 'args'],
            'unknown key in an entry' => [['functions' => ['f' => $ok + ['trusted' => true]]], 'trusted'],
            'unknown section' => [['gadgets' => []], 'gadgets'],
            'delegate without a reason' => [['functions' => ['f' => ['delegate' => 'X']]], 'why'],
            'refusal without a reason' => [['refused' => [['function' => 'event']]], 'why'],
            'refusal naming nothing' => [['refused' => [['why' => 'A reason that is long enough to count.']]], 'refused'],
            'listed and refused: function' => [[
                'functions' => ['event' => $ok],
                'refused' => [['function' => 'event', 'why' => 'Runs listeners the tool cannot see.']],
            ], 'refused'],
            'listed and refused: method' => [[
                'methods' => ['Vendor\\Gate' => ['allows' => $ok]],
                'refused' => [['class' => 'Vendor\\Gate', 'methods' => '*', 'why' => 'Runs policies the tool cannot see.']],
            ], 'refused'],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('badTables')]
    public function testATableThatDoesNotStateItsGroundsIsRejected(array $data, string $mentions): void
    {
        try {
            KnownTable::fromArray($data);
            $this->fail('the table should have been rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($mentions, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private static function answer(): array
    {
        return [
            'returns' => 'plain',
            'basis' => 'contract',
            'why' => 'A pure helper: it reads nothing from the database and calls nothing back.',
        ];
    }
}
