<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

/**
 * Builds the artisan export of a mini-app: what `route:list --json` and `model:show <class> --json`
 * would have printed. A case's export.php returns MiniAppExport::build(...); the runner writes it as
 * route-list.json and model-show/<Short>.json, the layout of fixtures/artisan-export.
 *
 *   use PeakSim\Tests\Support\MiniAppExport as E;
 *
 *   return E::build(
 *       routes: [E::route('/orders', 'App\Http\Controllers\OrderController@index')],
 *       models: E::standardModels(),
 *   );
 */
final class MiniAppExport
{
    /**
     * One row of route:list. The uri is given with or without a leading slash; artisan prints it without
     * (except for the root route, which stays "/").
     *
     * @param list<string> $middleware
     * @return array<string, mixed>
     */
    public static function route(
        string $uri,
        string $action,
        string $method = 'GET|HEAD',
        array $middleware = ['web'],
        ?string $name = null,
        ?string $path = null,
    ): array {
        $trimmed = ltrim($uri, '/');

        return [
            'domain' => null,
            'method' => $method,
            'uri' => $trimmed === '' ? '/' : $trimmed,
            'name' => $name,
            'action' => $action,
            'middleware' => $middleware,
            'path' => $path,
        ];
    }

    /**
     * One model:show document.
     *
     * @param array<string, array{0: string, 1: string}> $relations name => [type, related class]
     * @param list<string> $attributes column names (an "id" column is always first)
     * @param list<string> $events
     * @param list<string> $observers
     * @return array<string, mixed>
     */
    public static function model(
        string $class,
        string $table,
        array $relations = [],
        string $database = 'mysql',
        array $attributes = [],
        array $events = [],
        array $observers = [],
    ): array {
        $columns = [self::attribute('id', 'bigint unsigned', true)];
        foreach ($attributes as $name) {
            $columns[] = self::attribute($name, 'varchar(255)', false);
        }
        $rows = [];
        foreach ($relations as $name => [$type, $related]) {
            $rows[] = ['name' => $name, 'type' => $type, 'related' => $related];
        }

        return [
            'class' => $class,
            'database' => $database,
            'table' => $table,
            'policy' => null,
            'attributes' => $columns,
            'relations' => $rows,
            'events' => $events,
            'observers' => $observers,
            'collection' => 'Illuminate\\Database\\Eloquent\\Collection',
            'builder' => 'Illuminate\\Database\\Eloquent\\Builder',
            'resource' => null,
        ];
    }

    /**
     * The four models most cases share: Order(customer, items), Customer(orders), Item(order, product), Product(items).
     *
     * @return list<array<string, mixed>>
     */
    public static function standardModels(string $database = 'mysql'): array
    {
        return [
            self::model('App\\Models\\Order', 'orders', [
                'customer' => ['BelongsTo', 'App\\Models\\Customer'],
                'items' => ['HasMany', 'App\\Models\\Item'],
            ], $database, ['status', 'total']),
            self::model('App\\Models\\Customer', 'customers', [
                'orders' => ['HasMany', 'App\\Models\\Order'],
            ], $database, ['name', 'email']),
            self::model('App\\Models\\Item', 'items', [
                'order' => ['BelongsTo', 'App\\Models\\Order'],
                'product' => ['BelongsTo', 'App\\Models\\Product'],
            ], $database, ['quantity']),
            self::model('App\\Models\\Product', 'products', [
                'items' => ['HasMany', 'App\\Models\\Item'],
            ], $database, ['name', 'price']),
        ];
    }

    /**
     * @param list<array<string, mixed>> $routes rows made by route()
     * @param list<array<string, mixed>> $models documents made by model(); a later one replaces an earlier one of the same class
     * @return array{route_list: list<array<string, mixed>>, model_show: array<string, array<string, mixed>>}
     */
    public static function build(array $routes, array $models = []): array
    {
        $byClass = [];
        foreach ($models as $model) {
            $byClass[(string) $model['class']] = $model;
        }
        ksort($byClass);

        return ['route_list' => array_values($routes), 'model_show' => $byClass];
    }

    /** Writes route-list.json and model-show/<Short>.json under $dir (artisan's pretty-printed JSON). */
    public static function writeTo(array $export, string $dir): void
    {
        MiniAppWorkspace::write($dir, 'route-list.json', self::encode($export['route_list']));
        foreach ($export['model_show'] as $class => $document) {
            $short = substr(strrchr('\\' . $class, '\\') ?: '', 1);
            MiniAppWorkspace::write($dir, 'model-show/' . $short . '.json', self::encode($document));
        }
    }

    /** @return array<string, mixed> */
    private static function attribute(string $name, string $type, bool $increments): array
    {
        return [
            'name' => $name,
            'type' => $type,
            'increments' => $increments,
            'nullable' => false,
            'default' => null,
            'unique' => $increments,
            'fillable' => !$increments,
            'hidden' => false,
            'appended' => null,
            'cast' => $increments ? 'int' : null,
        ];
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
