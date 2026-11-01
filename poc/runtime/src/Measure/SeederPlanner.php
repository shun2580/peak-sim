<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Route\RouteSpec;

/**
 * Data from the verification app's own deterministic seeder (`DatabaseSeeder::sized(customers, products, orders,
 * itemsPerOrder)`). Only the verification app is built this way (the author of the task allows its seeder for B2); for
 * any other app the generator is used. The shapes follow the verification app's own tests, so the measurement is
 * comparable with the expectation they produced.
 *
 *   orders     customers 20, products 20, orders N,  3 items per order
 *   customers  customers N,  products 20, orders 0,  (no orders: the write routes insert them)
 *   products   customers 1,  products N,  orders 0
 *   all        every table N (a route with no candidate table; the scope is global)
 *   itm        the second sweep: orders fixed at 20, items per order M
 */
final class SeederPlanner implements DataPlanner
{
    public const ITEMS_PER_ORDER = 3;

    public const M_FIXED_ORDERS = 20;

    /** @var list<int> */
    public const M_SCALES = [3, 30, 300];

    public function sweepsFor(RouteSpec $spec, array $scales): array
    {
        $table = self::bare($spec->static['n_table'] ?? null);
        $plan = in_array($table, ['orders', 'customers', 'products'], true) ? $table : 'all';

        $points = [];
        foreach ([0, ...$scales] as $n) {
            $points[] = new DatabasePoint(self::tagOf($plan), $n, self::recipe($plan, $n));
        }
        $sweeps = [new Sweep('N', $points)];

        if ($plan === 'orders' && self::bare($spec->static['m_table'] ?? null) === 'items') {
            $sweeps[] = new Sweep('M', array_map(
                static fn (int $m): DatabasePoint => new DatabasePoint('itm', $m, ['customers' => 20, 'products' => 20, 'orders' => self::M_FIXED_ORDERS, 'items' => $m]),
                self::M_SCALES,
            ));
        }

        return $sweeps;
    }

    public function inspectionPoint(array $scales): DatabasePoint
    {
        return new DatabasePoint('insp', $scales[0], self::recipe('all', $scales[0]));
    }

    private static function tagOf(string $plan): string
    {
        return ['orders' => 'ord', 'customers' => 'cust', 'products' => 'prod', 'all' => 'all'][$plan];
    }

    /** @return array{customers: int, products: int, orders: int, items: int} */
    private static function recipe(string $plan, int $n): array
    {
        return match ($plan) {
            'orders' => ['customers' => 20, 'products' => 20, 'orders' => $n, 'items' => self::ITEMS_PER_ORDER],
            'customers' => ['customers' => $n, 'products' => 20, 'orders' => 0, 'items' => self::ITEMS_PER_ORDER],
            'products' => ['customers' => 1, 'products' => $n, 'orders' => 0, 'items' => self::ITEMS_PER_ORDER],
            default => ['customers' => $n, 'products' => $n, 'orders' => $n, 'items' => self::ITEMS_PER_ORDER],
        };
    }

    private static function bare(mixed $table): ?string
    {
        if (!is_string($table) || $table === '') {
            return null;
        }
        $dot = strrpos($table, '.');

        return strtolower(trim($dot === false ? $table : substr($table, $dot + 1), '`"'));
    }
}
