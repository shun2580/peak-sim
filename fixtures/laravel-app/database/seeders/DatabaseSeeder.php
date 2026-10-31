<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic data set: the same sizes always produce the same rows (no faker, no randomness).
 *
 * Sizes come from env (defaults in parentheses), or from DatabaseSeeder::sized() in tests:
 *   SEED_CUSTOMERS (20), SEED_PRODUCTS (20), SEED_ORDERS (50), SEED_ITEMS_PER_ORDER (3)
 * e.g. SEED_ORDERS=10000 php artisan migrate:fresh --seed
 *
 * Ids are written explicitly (1..N), so the tables must be empty (migrate:fresh --seed).
 * product_stats is intentionally left empty (filled lazily by /ng/first-or-create-in-loop).
 */
class DatabaseSeeder extends Seeder
{
    private const CHUNK = 1000;

    private const STATUSES = ['pending', 'paid', 'shipped'];

    private ?array $sizes = null;

    public static function sized(int $customers, int $products, int $orders, int $itemsPerOrder): self
    {
        $seeder = new self;
        $seeder->sizes = compact('customers', 'products', 'orders', 'itemsPerOrder');

        return $seeder;
    }

    public function run(): void
    {
        $sizes = $this->sizes ?? [
            'customers' => (int) env('SEED_CUSTOMERS', 20),
            'products' => (int) env('SEED_PRODUCTS', 20),
            'orders' => (int) env('SEED_ORDERS', 50),
            'itemsPerOrder' => (int) env('SEED_ITEMS_PER_ORDER', 3),
        ];

        $this->seedCustomers($sizes['customers']);
        $this->seedProducts($sizes['products']);
        $this->seedOrders($sizes['customers'], $sizes['products'], $sizes['orders'], $sizes['itemsPerOrder']);
    }

    private function seedCustomers(int $count): void
    {
        $this->insertInChunks('customers', $count, fn (int $i) => [
            'id' => $i,
            'name' => "Customer {$i}",
            'email' => "customer{$i}@example.com",
        ]);
    }

    private function seedProducts(int $count): void
    {
        $this->insertInChunks('products', $count, fn (int $i) => [
            'id' => $i,
            'name' => "Product {$i}",
            'price' => $this->priceOf($i),
        ]);
    }

    private function seedOrders(int $customers, int $products, int $orders, int $itemsPerOrder): void
    {
        if ($customers < 1 || $products < 1) {
            return;
        }

        $base = strtotime('2026-01-01 00:00:00 UTC');

        for ($start = 1; $start <= $orders; $start += self::CHUNK) {
            $end = min($start + self::CHUNK - 1, $orders);
            $orderRows = [];
            $itemRows = [];

            for ($o = $start; $o <= $end; $o++) {
                $total = 0.0;
                for ($k = 0; $k < $itemsPerOrder; $k++) {
                    $productId = (($o * $itemsPerOrder + $k) % $products) + 1;
                    $quantity = 1 + (($o + $k) % 5);
                    $price = $this->priceOf($productId);
                    $total += $quantity * (float) $price;
                    $itemRows[] = [
                        'order_id' => $o,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'price' => $price,
                    ];
                }
                $at = gmdate('Y-m-d H:i:s', $base + $o * 60);
                $orderRows[] = [
                    'id' => $o,
                    'customer_id' => (($o - 1) % $customers) + 1,
                    'status' => self::STATUSES[$o % count(self::STATUSES)],
                    'total' => number_format($total, 2, '.', ''),
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }

            DB::table('orders')->insert($orderRows);
            foreach (array_chunk($itemRows, self::CHUNK) as $chunk) {
                DB::table('items')->insert($chunk);
            }
        }
    }

    private function priceOf(int $productId): string
    {
        return number_format(100 + ($productId * 37) % 900, 2, '.', '');
    }

    /** @param  callable(int): array<string, mixed>  $row */
    private function insertInChunks(string $table, int $count, callable $row): void
    {
        for ($start = 1; $start <= $count; $start += self::CHUNK) {
            $rows = [];
            for ($i = $start; $i <= min($start + self::CHUNK - 1, $count); $i++) {
                $rows[] = $row($i);
            }
            DB::table($table)->insert($rows);
        }
    }
}
