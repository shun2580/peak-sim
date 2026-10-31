<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Input;

use PeakSim\Input\ArtisanFailedException;
use PeakSim\Input\ArtisanOutputNotJsonException;
use PeakSim\Input\ArtisanSource;
use PeakSim\Input\ExportDirSource;
use PeakSim\Input\ModelInfo;
use PeakSim\Input\RelationInfo;
use PeakSim\Input\RouteEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExportDirSourceTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../../fixtures/artisan-export';

    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/peak-sim-export-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/model-show', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/model-show/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        if (is_dir($this->tmp)) {
            rmdir($this->tmp);
        }
    }

    public function testItIsAnArtisanSource(): void
    {
        self::assertInstanceOf(ArtisanSource::class, new ExportDirSource(self::FIXTURE_DIR));
    }

    // ---- the real export of the fixture app -------------------------------------------------------------

    public function testRoutesOfTheFixtureExportAreAllThere(): void
    {
        $routes = (new ExportDirSource(self::FIXTURE_DIR))->routes();

        self::assertCount(26, $routes);
        self::assertContainsOnlyInstancesOf(RouteEntry::class, $routes);
    }

    public function testRoutesAreSortedByUriAscendingAndKeepTheLeadingSlash(): void
    {
        $uris = array_map(static fn (RouteEntry $r): string => $r->uri, (new ExportDirSource(self::FIXTURE_DIR))->routes());

        $sorted = $uris;
        usort($sorted, 'strcmp');
        self::assertSame($sorted, $uris);
        self::assertSame('/hidden/appends', $uris[0]);
        foreach ($uris as $uri) {
            self::assertStringStartsWith('/', $uri);
            self::assertStringNotContainsString('//', $uri);
        }
    }

    public function testOkBulkKeepsThePostMethodOfTheExport(): void
    {
        $bulk = $this->routeByUri((new ExportDirSource(self::FIXTURE_DIR))->routes(), '/ok/bulk');

        self::assertSame(['POST'], $bulk->methods);
        self::assertSame('App\\Http\\Controllers\\Ok\\BulkController', $bulk->action);
        self::assertSame(['web'], $bulk->middleware);
        self::assertNull($bulk->name);
        self::assertNull($bulk->domain);
        self::assertNull($bulk->path);
    }

    public function testGetHeadIsSplitIntoTwoMethods(): void
    {
        $route = $this->routeByUri((new ExportDirSource(self::FIXTURE_DIR))->routes(), '/ok/eager');

        self::assertSame(['GET', 'HEAD'], $route->methods);
        self::assertSame('App\\Http\\Controllers\\Ok\\OrderController@eager', $route->action);
    }

    public function testClosureRoutesKeepTheirFileAndLineAndTheirName(): void
    {
        $storage = array_values(array_filter(
            (new ExportDirSource(self::FIXTURE_DIR))->routes(),
            static fn (RouteEntry $r): bool => $r->uri === '/storage/{path}',
        ));

        self::assertCount(2, $storage);
        foreach ($storage as $route) {
            self::assertSame('Closure', $route->action);
            self::assertNotNull($route->path);
            self::assertSame([], $route->middleware);
        }
        $names = array_map(static fn (RouteEntry $r): ?string => $r->name, $storage);
        sort($names);
        self::assertSame(['storage.local', 'storage.local.upload'], $names);
    }

    public function testModelInfoOfOrderFromTheFixtureExport(): void
    {
        $info = (new ExportDirSource(self::FIXTURE_DIR))->modelInfo('App\\Models\\Order');

        self::assertInstanceOf(ModelInfo::class, $info);
        self::assertSame('App\\Models\\Order', $info->class);
        self::assertSame('orders', $info->table);
        self::assertSame('mysql', $info->database);
        self::assertSame(['customer', 'items'], array_keys($info->relations));
        self::assertEquals(
            new RelationInfo('customer', 'BelongsTo', 'App\\Models\\Customer', false),
            $info->relations['customer'],
        );
        self::assertEquals(
            new RelationInfo('items', 'HasMany', 'App\\Models\\Item', true),
            $info->relations['items'],
        );
        self::assertNotSame([], $info->attributes);
        self::assertSame([], $info->events);
        self::assertSame([], $info->observers);
    }

    public function testEveryModelFileOfTheFixtureExportCanBeRead(): void
    {
        $source = new ExportDirSource(self::FIXTURE_DIR);

        foreach (glob(self::FIXTURE_DIR . '/model-show/*.json') ?: [] as $file) {
            $class = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['class'];
            self::assertNotNull($source->modelInfo($class), $class);
        }
    }

    public function testHasOneIsNotMany(): void
    {
        $info = (new ExportDirSource(self::FIXTURE_DIR))->modelInfo('App\\Models\\Product');

        self::assertNotNull($info);
        self::assertFalse($info->relations['stat']->many);
        self::assertTrue($info->relations['items']->many);
    }

    public function testUnknownClassGivesNull(): void
    {
        self::assertNull((new ExportDirSource(self::FIXTURE_DIR))->modelInfo('App\\Models\\NoSuchModel'));
    }

    public function testLeadingBackslashOfTheClassMakesNoDifference(): void
    {
        $source = new ExportDirSource(self::FIXTURE_DIR);

        self::assertEquals(
            $source->modelInfo('App\\Models\\Order'),
            $source->modelInfo('\\App\\Models\\Order'),
        );
        self::assertNotNull($source->modelInfo('\\App\\Models\\Order'));
    }

    // ---- shapes of Laravel 11 / 12 / 13 ------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function laravelVersionProvider(): array
    {
        return ['laravel 11' => ['11'], 'laravel 12' => ['12'], 'laravel 13' => ['13']];
    }

    #[DataProvider('laravelVersionProvider')]
    public function testRouteListOfEachLaravelVersionGivesTheSameEntries(string $version): void
    {
        $this->writeRouteList($this->routeListShape($version));

        $routes = (new ExportDirSource($this->tmp))->routes();

        self::assertSame(['/', '/orders', '/orders/{order}', '/up'], array_map(static fn (RouteEntry $r): string => $r->uri, $routes));
        $orders = $this->routeByUri($routes, '/orders');
        self::assertSame(['GET', 'HEAD'], $orders->methods);
        self::assertSame('orders.index', $orders->name);
        self::assertSame(['web', 'throttle:60,1'], $orders->middleware);
        self::assertSame('App\\Http\\Controllers\\OrderController@index', $orders->action);
    }

    public function testPathKeyExistsOnlyFromLaravel12(): void
    {
        $this->writeRouteList($this->routeListShape('11'));
        self::assertNull($this->routeByUri((new ExportDirSource($this->tmp))->routes(), '/orders')->path);

        $this->writeRouteList($this->routeListShape('12'));
        self::assertSame(
            'routes/web.php:5',
            $this->routeByUri((new ExportDirSource($this->tmp))->routes(), '/orders/{order}')->path,
        );
    }

    public function testTheRootRouteStaysASingleSlash(): void
    {
        $this->writeRouteList($this->routeListShape('13'));

        $routes = (new ExportDirSource($this->tmp))->routes();

        self::assertSame('/', $routes[0]->uri);
    }

    public function testDomainIsKept(): void
    {
        $this->writeRouteList([
            ['domain' => 'api.example.test', 'method' => 'GET|HEAD', 'uri' => 'ping', 'name' => null, 'action' => 'Closure', 'middleware' => []],
        ]);

        self::assertSame('api.example.test', (new ExportDirSource($this->tmp))->routes()[0]->domain);
    }

    #[DataProvider('laravelVersionProvider')]
    public function testModelShowOfEachLaravelVersionGivesTheSameModelInfo(string $version): void
    {
        $this->writeRouteList([]);
        $this->writeModel('whatever-name.json', $this->modelShape($version));

        $info = (new ExportDirSource($this->tmp))->modelInfo('App\\Models\\Order');

        self::assertNotNull($info);
        self::assertSame('orders', $info->table);
        self::assertSame('mysql', $info->database);
        self::assertSame(['customer', 'items', 'tags'], array_keys($info->relations));
        self::assertTrue($info->relations['tags']->many);
        self::assertFalse($info->relations['customer']->many);
        self::assertCount(2, $info->attributes);
    }

    public function testFileNameDoesNotMatterOnlyTheClassInsideDoes(): void
    {
        $this->writeRouteList([]);
        $this->writeModel('zzz.json', $this->modelShape('13'));

        $source = new ExportDirSource($this->tmp);

        self::assertNotNull($source->modelInfo('App\\Models\\Order'));
        self::assertNull($source->modelInfo('zzz'));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function relationTypeProvider(): array
    {
        return [
            'BelongsTo' => ['BelongsTo', false],
            'HasOne' => ['HasOne', false],
            'HasOneThrough' => ['HasOneThrough', false],
            'MorphOne' => ['MorphOne', false],
            'MorphTo' => ['MorphTo', false],
            'HasMany' => ['HasMany', true],
            'BelongsToMany' => ['BelongsToMany', true],
            'HasManyThrough' => ['HasManyThrough', true],
            'MorphMany' => ['MorphMany', true],
            'MorphToMany' => ['MorphToMany', true],
            'MorphedByMany' => ['MorphedByMany', true],
            'qualified name' => ['Illuminate\\Database\\Eloquent\\Relations\\HasMany', true],
        ];
    }

    #[DataProvider('relationTypeProvider')]
    public function testManyIsDerivedFromTheRelationType(string $type, bool $many): void
    {
        $this->writeRouteList([]);
        $model = $this->modelShape('13');
        $model['relations'] = [['name' => 'rel', 'type' => $type, 'related' => 'App\\Models\\Customer']];
        $this->writeModel('Order.json', $model);

        $info = (new ExportDirSource($this->tmp))->modelInfo('App\\Models\\Order');

        self::assertNotNull($info);
        self::assertSame($many, $info->relations['rel']->many);
    }

    public function testMorphToWithoutRelatedKeepsNull(): void
    {
        $this->writeRouteList([]);
        $model = $this->modelShape('13');
        $model['relations'] = [['name' => 'owner', 'type' => 'MorphTo', 'related' => null]];
        $this->writeModel('Order.json', $model);

        $info = (new ExportDirSource($this->tmp))->modelInfo('App\\Models\\Order');

        self::assertNotNull($info);
        self::assertNull($info->relations['owner']->related);
    }

    public function testEventsAndObserversAreKeptAsTheyAre(): void
    {
        $this->writeRouteList([]);
        $model = $this->modelShape('13');
        $model['events'] = [['event' => 'created', 'class' => 'App\\Events\\OrderCreated']];
        $model['observers'] = [['event' => 'saving', 'observer' => ['App\\Observers\\OrderObserver']]];
        $this->writeModel('Order.json', $model);

        $info = (new ExportDirSource($this->tmp))->modelInfo('App\\Models\\Order');

        self::assertNotNull($info);
        self::assertSame([['event' => 'created', 'class' => 'App\\Events\\OrderCreated']], $info->events);
        self::assertSame([['event' => 'saving', 'observer' => ['App\\Observers\\OrderObserver']]], $info->observers);
    }

    // ---- laziness and retention --------------------------------------------------------------------------

    public function testRoutesDoNotNeedTheModelFiles(): void
    {
        $this->writeRouteList($this->routeListShape('13'));
        file_put_contents($this->tmp . '/model-show/Broken.json', '{ not json');

        self::assertCount(4, (new ExportDirSource($this->tmp))->routes());
    }

    public function testNothingIsReadBeforeItIsAsked(): void
    {
        $source = new ExportDirSource($this->tmp . '/does-not-exist-yet');

        self::assertInstanceOf(ExportDirSource::class, $source);
    }

    public function testRoutesAreKeptAfterTheFirstRead(): void
    {
        $this->writeRouteList($this->routeListShape('13'));
        $source = new ExportDirSource($this->tmp);
        $first = $source->routes();

        unlink($this->tmp . '/route-list.json');

        self::assertEquals($first, $source->routes());
    }

    public function testModelInfoIsKeptAfterTheFirstRead(): void
    {
        $this->writeRouteList([]);
        $this->writeModel('Order.json', $this->modelShape('13'));
        $source = new ExportDirSource($this->tmp);
        $first = $source->modelInfo('App\\Models\\Order');

        unlink($this->tmp . '/model-show/Order.json');

        self::assertNotNull($first);
        self::assertSame($first, $source->modelInfo('App\\Models\\Order'));
    }

    // ---- failures ----------------------------------------------------------------------------------------

    public function testBrokenRouteListIsAnOutputNotJsonException(): void
    {
        file_put_contents($this->tmp . '/route-list.json', "PHP Deprecated: x\n[]");

        $this->expectException(ArtisanOutputNotJsonException::class);

        (new ExportDirSource($this->tmp))->routes();
    }

    public function testRouteListOfTheWrongShapeIsAnOutputNotJsonException(): void
    {
        file_put_contents($this->tmp . '/route-list.json', '{"message":"nope"}');

        $this->expectException(ArtisanOutputNotJsonException::class);

        (new ExportDirSource($this->tmp))->routes();
    }

    public function testMissingRouteListIsAnArtisanFailedExceptionThatNamesTheFile(): void
    {
        try {
            (new ExportDirSource($this->tmp))->routes();
            self::fail('an exception was expected');
        } catch (ArtisanFailedException $e) {
            self::assertNotInstanceOf(ArtisanOutputNotJsonException::class, $e);
            self::assertStringContainsString('route-list.json', $e->getMessage());
        }
    }

    public function testBrokenModelFileIsAnOutputNotJsonExceptionThatNamesTheFile(): void
    {
        $this->writeRouteList([]);
        file_put_contents($this->tmp . '/model-show/Broken.json', '{ not json');

        try {
            (new ExportDirSource($this->tmp))->modelInfo('App\\Models\\Order');
            self::fail('an exception was expected');
        } catch (ArtisanOutputNotJsonException $e) {
            self::assertStringContainsString('Broken.json', $e->getMessage());
        }
    }

    public function testModelFileWithoutClassIsAnOutputNotJsonException(): void
    {
        $this->writeRouteList([]);
        file_put_contents($this->tmp . '/model-show/NoClass.json', '{"table":"orders"}');

        $this->expectException(ArtisanOutputNotJsonException::class);

        (new ExportDirSource($this->tmp))->modelInfo('App\\Models\\Order');
    }

    public function testMissingModelShowDirectoryGivesNull(): void
    {
        $this->writeRouteList([]);
        rmdir($this->tmp . '/model-show');

        self::assertNull((new ExportDirSource($this->tmp))->modelInfo('App\\Models\\Order'));
    }

    // ---- helpers -----------------------------------------------------------------------------------------

    /** @param list<RouteEntry> $routes */
    private function routeByUri(array $routes, string $uri): RouteEntry
    {
        foreach ($routes as $route) {
            if ($route->uri === $uri) {
                return $route;
            }
        }
        self::fail("no route {$uri}");
    }

    /** @param list<array<string, mixed>> $rows */
    private function writeRouteList(array $rows): void
    {
        file_put_contents($this->tmp . '/route-list.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $model */
    private function writeModel(string $file, array $model): void
    {
        file_put_contents($this->tmp . '/model-show/' . $file, json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * route:list --json as Laravel 11 (no "path"), 12 and 13 (with "path") print it. Rows come in a shuffled order
     * on purpose; the uri has no leading slash except for the root.
     *
     * @return list<array<string, mixed>>
     */
    private function routeListShape(string $version): array
    {
        $rows = [
            ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'orders/{order}', 'name' => null, 'action' => 'App\\Http\\Controllers\\OrderController@show', 'middleware' => ['web'], 'path' => null],
            ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'up', 'name' => null, 'action' => $version === '13' ? 'Illuminate\\Foundation\\Http\\HealthCheck' : 'Closure', 'middleware' => [], 'path' => $version === '13' ? null : 'routes/web.php:5'],
            ['domain' => null, 'method' => 'GET|HEAD', 'uri' => '/', 'name' => null, 'action' => 'Closure', 'middleware' => ['web'], 'path' => 'routes/web.php:3'],
            ['domain' => null, 'method' => 'GET|HEAD', 'uri' => 'orders', 'name' => 'orders.index', 'action' => 'App\\Http\\Controllers\\OrderController@index', 'middleware' => ['web', 'throttle:60,1'], 'path' => null],
        ];
        $rows[0]['path'] = 'routes/web.php:5';
        if ($version === '11') {
            $rows = array_map(static function (array $row): array {
                unset($row['path']);

                return $row;
            }, $rows);
        }

        return $rows;
    }

    /** @return array<string, mixed> model:show --json for App\Models\Order as Laravel 11 / 12 / 13 print it */
    private function modelShape(string $version): array
    {
        $model = [
            'class' => 'App\\Models\\Order',
            'database' => 'mysql',
            'table' => 'orders',
            'policy' => null,
            'attributes' => [
                ['name' => 'id', 'type' => 'bigint unsigned', 'increments' => true, 'nullable' => false, 'default' => null, 'unique' => true, 'fillable' => false, 'hidden' => false, 'appended' => null, 'cast' => 'int'],
                ['name' => 'status', 'type' => 'varchar(255)', 'increments' => false, 'nullable' => false, 'default' => null, 'unique' => false, 'fillable' => true, 'hidden' => false, 'appended' => null, 'cast' => null],
            ],
            'relations' => [
                ['name' => 'customer', 'type' => 'BelongsTo', 'related' => 'App\\Models\\Customer'],
                ['name' => 'items', 'type' => 'HasMany', 'related' => 'App\\Models\\Item'],
                ['name' => 'tags', 'type' => 'BelongsToMany', 'related' => 'App\\Models\\Tag'],
            ],
            'events' => [],
            'observers' => [],
        ];
        if ($version === '13') {
            $model['collection'] = 'Illuminate\\Database\\Eloquent\\Collection';
            $model['builder'] = 'Illuminate\\Database\\Eloquent\\Builder';
            $model['resource'] = null;
        }

        return $model;
    }
}
