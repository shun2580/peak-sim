<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data;

use PeakSim\Runtime\Data\ColumnSchema;
use PeakSim\Runtime\Data\DatabaseSchema;
use PeakSim\Runtime\Data\ForeignKey;
use PeakSim\Runtime\Data\TableSchema;

/** Hand-made schemas for the unit tests (the shapes SchemaReader produces from information_schema). */
final class Schemas
{
    public static function id(string $name = 'id'): ColumnSchema
    {
        return new ColumnSchema($name, 'bigint', 'bigint unsigned', precision: 20, scale: 0, autoIncrement: true, unsigned: true);
    }

    public static function fk(string $name, bool $nullable = false): ColumnSchema
    {
        return new ColumnSchema($name, 'bigint', 'bigint unsigned', precision: 20, scale: 0, nullable: $nullable, unsigned: true);
    }

    public static function varchar(string $name, int $length = 255, bool $nullable = false): ColumnSchema
    {
        return new ColumnSchema($name, 'varchar', sprintf('varchar(%d)', $length), length: $length, nullable: $nullable);
    }

    public static function column(string $name, string $dataType, string $columnType = '', array $extra = []): ColumnSchema
    {
        return new ColumnSchema(
            $name,
            $dataType,
            $columnType !== '' ? $columnType : $dataType,
            length: $extra['length'] ?? null,
            precision: $extra['precision'] ?? null,
            scale: $extra['scale'] ?? null,
            nullable: $extra['nullable'] ?? false,
            default: $extra['default'] ?? null,
            hasDefault: $extra['hasDefault'] ?? false,
            autoIncrement: $extra['autoIncrement'] ?? false,
            generated: $extra['generated'] ?? false,
            unsigned: $extra['unsigned'] ?? false,
            enumValues: $extra['enumValues'] ?? [],
        );
    }

    /** The five tables of the verification app: customers <- orders <- items -> products <- product_stats. */
    public static function shop(): DatabaseSchema
    {
        return new DatabaseSchema([
            new TableSchema('customers', [self::id(), self::varchar('name'), self::varchar('email')], ['id'], [['email']]),
            new TableSchema('products', [
                self::id(),
                self::varchar('name'),
                self::column('price', 'decimal', 'decimal(10,2)', ['precision' => 10, 'scale' => 2]),
            ], ['id']),
            new TableSchema('orders', [
                self::id(),
                self::fk('customer_id'),
                self::varchar('status'),
                self::column('total', 'decimal', 'decimal(12,2)', ['precision' => 12, 'scale' => 2]),
                self::column('created_at', 'timestamp', 'timestamp', ['nullable' => true]),
                self::column('updated_at', 'timestamp', 'timestamp', ['nullable' => true]),
            ], ['id'], [], [new ForeignKey('orders_customer_id_foreign', ['customer_id'], 'customers', ['id'])]),
            new TableSchema('items', [
                self::id(),
                self::fk('order_id'),
                self::fk('product_id'),
                self::column('quantity', 'int', 'int unsigned', ['precision' => 10, 'scale' => 0, 'unsigned' => true]),
                self::column('price', 'decimal', 'decimal(10,2)', ['precision' => 10, 'scale' => 2]),
            ], ['id'], [], [
                new ForeignKey('items_order_id_foreign', ['order_id'], 'orders', ['id']),
                new ForeignKey('items_product_id_foreign', ['product_id'], 'products', ['id']),
            ]),
            new TableSchema('product_stats', [
                self::id(),
                self::fk('product_id'),
                self::column('units_sold', 'int', 'int unsigned', ['precision' => 10, 'scale' => 0, 'unsigned' => true, 'hasDefault' => true, 'default' => '0']),
                self::column('created_at', 'timestamp', 'timestamp', ['nullable' => true]),
                self::column('updated_at', 'timestamp', 'timestamp', ['nullable' => true]),
            ], ['id'], [['product_id']], [new ForeignKey('product_stats_product_id_foreign', ['product_id'], 'products', ['id'])]),
        ]);
    }
}
