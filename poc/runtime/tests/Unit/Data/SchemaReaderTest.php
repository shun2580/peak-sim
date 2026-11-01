<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data;

use PeakSim\Runtime\Data\SchemaReader;
use PHPUnit\Framework\TestCase;

/** SchemaReader::fromRows turns the rows of information_schema into the schema model (structure only). */
final class SchemaReaderTest extends TestCase
{
    /** @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>, 2: list<array<string,mixed>>, 3: list<array<string,mixed>>} */
    private function rows(): array
    {
        $tables = [['table_name' => 'orders'], ['table_name' => 'customers']];
        $columns = [
            ['table_name' => 'customers', 'column_name' => 'id', 'position' => '1', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned', 'char_length' => null, 'num_precision' => '20', 'num_scale' => '0', 'is_nullable' => 'NO', 'column_default' => null, 'extra' => 'auto_increment'],
            ['table_name' => 'customers', 'column_name' => 'name', 'position' => '2', 'data_type' => 'varchar', 'column_type' => 'varchar(255)', 'char_length' => '255', 'num_precision' => null, 'num_scale' => null, 'is_nullable' => 'NO', 'column_default' => null, 'extra' => ''],
            ['table_name' => 'orders', 'column_name' => 'id', 'position' => '1', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned', 'char_length' => null, 'num_precision' => '20', 'num_scale' => '0', 'is_nullable' => 'NO', 'column_default' => null, 'extra' => 'auto_increment'],
            ['table_name' => 'orders', 'column_name' => 'customer_id', 'position' => '2', 'data_type' => 'bigint', 'column_type' => 'bigint unsigned', 'char_length' => null, 'num_precision' => '20', 'num_scale' => '0', 'is_nullable' => 'NO', 'column_default' => null, 'extra' => ''],
            ['table_name' => 'orders', 'column_name' => 'status', 'position' => '3', 'data_type' => 'enum', 'column_type' => "enum('new','it''s done')", 'char_length' => '9', 'num_precision' => null, 'num_scale' => null, 'is_nullable' => 'YES', 'column_default' => 'new', 'extra' => ''],
            ['table_name' => 'orders', 'column_name' => 'total', 'position' => '4', 'data_type' => 'decimal', 'column_type' => 'decimal(12,2)', 'char_length' => null, 'num_precision' => '12', 'num_scale' => '2', 'is_nullable' => 'NO', 'column_default' => '0.00', 'extra' => ''],
            ['table_name' => 'orders', 'column_name' => 'slug', 'position' => '5', 'data_type' => 'varchar', 'column_type' => 'varchar(20)', 'char_length' => '20', 'num_precision' => null, 'num_scale' => null, 'is_nullable' => 'YES', 'column_default' => null, 'extra' => 'VIRTUAL GENERATED'],
            ['table_name' => 'orders', 'column_name' => 'seen_at', 'position' => '6', 'data_type' => 'timestamp', 'column_type' => 'timestamp', 'char_length' => null, 'num_precision' => null, 'num_scale' => null, 'is_nullable' => 'NO', 'column_default' => 'CURRENT_TIMESTAMP', 'extra' => 'DEFAULT_GENERATED'],
        ];
        $keys = [
            ['table_name' => 'orders', 'constraint_name' => 'orders_customer_id_foreign', 'column_name' => 'customer_id', 'position' => '1', 'ref_table' => 'customers', 'ref_column' => 'id'],
        ];
        $stats = [
            ['table_name' => 'customers', 'index_name' => 'PRIMARY', 'non_unique' => '0', 'seq' => '1', 'column_name' => 'id'],
            ['table_name' => 'orders', 'index_name' => 'PRIMARY', 'non_unique' => '0', 'seq' => '1', 'column_name' => 'id'],
            ['table_name' => 'orders', 'index_name' => 'orders_slug_unique', 'non_unique' => '0', 'seq' => '1', 'column_name' => 'slug'],
            ['table_name' => 'orders', 'index_name' => 'orders_customer_id_foreign', 'non_unique' => '1', 'seq' => '1', 'column_name' => 'customer_id'],
            ['table_name' => 'orders', 'index_name' => 'orders_fn', 'non_unique' => '0', 'seq' => '1', 'column_name' => null],
        ];

        return [$tables, $columns, $keys, $stats];
    }

    public function testTablesAreSortedByName(): void
    {
        $schema = SchemaReader::fromRows(...$this->rows());

        $this->assertSame(['customers', 'orders'], $schema->names());
    }

    public function testColumnsKeepTheirOrderAndFacts(): void
    {
        $orders = SchemaReader::fromRows(...$this->rows())->table('orders');

        $this->assertSame(['id', 'customer_id', 'status', 'total', 'slug', 'seen_at'], array_map(fn ($c) => $c->name, $orders->columns));
        $id = $orders->column('id');
        $this->assertTrue($id->autoIncrement);
        $this->assertTrue($id->unsigned);
        $this->assertFalse($id->nullable);
        $total = $orders->column('total');
        $this->assertSame([12, 2], [$total->precision, $total->scale]);
        $this->assertTrue($total->hasDefault);
        $this->assertSame('0.00', $total->default);
        $this->assertSame(255, (SchemaReader::fromRows(...$this->rows())->table('customers')->column('name'))->length);
    }

    public function testEnumValuesAreParsedIncludingQuotes(): void
    {
        $status = SchemaReader::fromRows(...$this->rows())->table('orders')->column('status');

        $this->assertSame(['new', "it's done"], $status->enumValues);
        $this->assertTrue($status->nullable);
    }

    public function testGeneratedColumnsAreRecognisedButExpressionDefaultsAreNot(): void
    {
        $orders = SchemaReader::fromRows(...$this->rows())->table('orders');

        $this->assertTrue($orders->column('slug')->generated);
        $this->assertFalse($orders->column('seen_at')->generated);
        $this->assertTrue($orders->column('seen_at')->hasDefault);
    }

    public function testNullableWithoutADefaultCountsAsHavingTheNullDefault(): void
    {
        $slug = SchemaReader::fromRows(...$this->rows())->table('orders')->column('slug');

        $this->assertTrue($slug->hasDefault);
        $this->assertNull($slug->default);
    }

    public function testNotNullWithoutADefaultHasNoDefault(): void
    {
        $name = SchemaReader::fromRows(...$this->rows())->table('customers')->column('name');

        $this->assertFalse($name->hasDefault);
    }

    public function testPrimaryAndUniqueIndexesAreCollected(): void
    {
        $orders = SchemaReader::fromRows(...$this->rows())->table('orders');

        $this->assertSame(['id'], $orders->primaryKey);
        $this->assertSame([['slug']], $orders->uniqueIndexes);
    }

    public function testForeignKeysAreCollected(): void
    {
        $orders = SchemaReader::fromRows(...$this->rows())->table('orders');

        $this->assertCount(1, $orders->foreignKeys);
        $fk = $orders->foreignKeys[0];
        $this->assertSame('orders_customer_id_foreign', $fk->name);
        $this->assertSame(['customer_id'], $fk->columns);
        $this->assertSame('customers', $fk->refTable);
        $this->assertSame(['id'], $fk->refColumns);
    }

    public function testACompositeForeignKeyKeepsItsColumnPairs(): void
    {
        [$tables, $columns, $keys, $stats] = $this->rows();
        $keys = [
            ['table_name' => 'orders', 'constraint_name' => 'c', 'column_name' => 'b', 'position' => '2', 'ref_table' => 'customers', 'ref_column' => 'y'],
            ['table_name' => 'orders', 'constraint_name' => 'c', 'column_name' => 'a', 'position' => '1', 'ref_table' => 'customers', 'ref_column' => 'x'],
        ];
        $fk = SchemaReader::fromRows($tables, $columns, $keys, $stats)->table('orders')->foreignKeys[0];

        $this->assertSame(['a', 'b'], $fk->columns);
        $this->assertSame(['x', 'y'], $fk->refColumns);
    }

    public function testTheTableOfAnUnknownNameIsNull(): void
    {
        $this->assertNull(SchemaReader::fromRows(...$this->rows())->table('nope'));
    }

    public function testColumnsOfTablesThatAreNotBaseTablesAreIgnored(): void
    {
        [$tables, $columns, $keys, $stats] = $this->rows();
        $columns[] = ['table_name' => 'a_view', 'column_name' => 'x', 'position' => '1', 'data_type' => 'int', 'column_type' => 'int', 'char_length' => null, 'num_precision' => '10', 'num_scale' => '0', 'is_nullable' => 'YES', 'column_default' => null, 'extra' => ''];

        $this->assertSame(['customers', 'orders'], SchemaReader::fromRows($tables, $columns, $keys, $stats)->names());
    }
}
