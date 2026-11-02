<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data;

use PeakSim\Runtime\Data\ColumnSchema;
use PeakSim\Runtime\Data\TableSchema;
use PeakSim\Runtime\Data\ValueFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValueFactoryTest extends TestCase
{
    private function table(ColumnSchema ...$columns): TableSchema
    {
        return new TableSchema('things', array_values($columns), []);
    }

    private function value(ColumnSchema $column, int $i, ?TableSchema $table = null): mixed
    {
        return (new ValueFactory())->value($table ?? $this->table($column), $column, $i);
    }

    public function testStringValueIsTableColumnAndRow(): void
    {
        $this->assertSame('things_title_7', $this->value(Schemas::varchar('title'), 7));
    }

    public function testStringValueRespectsTheLength(): void
    {
        $value = $this->value(Schemas::varchar('a_rather_long_column_name', 12), 123456);

        $this->assertSame(12, strlen($value));
    }

    public function testUniqueStringKeepsTheRowNumberWhenItIsCut(): void
    {
        $column = Schemas::varchar('slug', 10);
        $table = new TableSchema('things', [$column], [], [['slug']]);
        $values = [];
        for ($i = 1; $i <= 2000; $i++) {
            $values[] = (new ValueFactory())->value($table, $column, $i);
        }

        $this->assertCount(2000, array_unique($values));
        $this->assertLessThanOrEqual(10, max(array_map('strlen', $values)));
        $this->assertStringEndsWith('_2000', $values[1999]);
    }

    public function testUniqueStringStaysDistinctEvenWhenTheColumnIsTooShortForTheSuffix(): void
    {
        $column = Schemas::varchar('code', 3);
        $table = new TableSchema('things', [$column], [], [['code']]);
        $values = [];
        for ($i = 1; $i <= 100; $i++) {
            $values[] = (new ValueFactory())->value($table, $column, $i);
        }

        $this->assertCount(100, array_unique($values));
        $this->assertLessThanOrEqual(3, max(array_map('strlen', $values)));
    }

    public function testValuesAreDeterministic(): void
    {
        $column = Schemas::varchar('title');
        $this->assertSame($this->value($column, 5), $this->value($column, 5));
    }

    public function testNamesWithAwkwardCharactersAreMadeSafe(): void
    {
        $this->assertSame('t_a_b_c_1', (new ValueFactory())->value(
            new TableSchema('t', [Schemas::varchar('a b-c')], []),
            Schemas::varchar('a b-c'),
            1,
        ));
    }

    public function testEmailLikeColumnsGetAnAddress(): void
    {
        $value = $this->value(Schemas::varchar('email'), 3);

        $this->assertMatchesRegularExpression('/^[a-z0-9_]+@example\.test$/', $value);
        $this->assertNotSame($value, $this->value(Schemas::varchar('email'), 4));
    }

    public function testUuidShapedColumnsGetAUuidThatIsUniquePerRow(): void
    {
        $column = Schemas::column('uuid', 'char', 'char(36)', ['length' => 36]);
        $a = $this->value($column, 1);
        $b = $this->value($column, 2);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-0000-4000-8000-[0-9a-f]{12}$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function testUlidShapedColumnsGetAValidUlidThatIsUniquePerRow(): void
    {
        foreach (['char', 'varchar'] as $type) {
            $column = Schemas::column('id', $type, $type . '(26)', ['length' => 26]);
            $seen = [];
            foreach ([1, 2, 10, 1000, 123456] as $i) {
                $value = $this->value($column, $i);
                // What Laravel's HasUlids checks before it looks a row up: 26 characters of Crockford's base32, first one 0-7.
                $this->assertMatchesRegularExpression('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value);
                $seen[$value] = true;
            }
            $this->assertCount(5, $seen);
        }
    }

    public function testAUlidIsTheSameForTheSameTableColumnAndRow(): void
    {
        $column = Schemas::column('id', 'char', 'char(26)', ['length' => 26]);

        $this->assertSame($this->value($column, 7), $this->value($column, 7));
    }

    #[DataProvider('integerRanges')]
    public function testIntegersStayInsideTheirRange(string $type, bool $unsigned, int $max): void
    {
        $column = Schemas::column('n', $type, $type . ($unsigned ? ' unsigned' : ''), ['unsigned' => $unsigned, 'precision' => 10, 'scale' => 0]);

        foreach ([1, 2, 100, 1000, 100000, 10_000_000] as $i) {
            $value = $this->value($column, $i);
            $this->assertIsInt($value);
            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThanOrEqual($max, $value);
        }
    }

    /** @return array<string, array{0: string, 1: bool, 2: int}> */
    public static function integerRanges(): array
    {
        return [
            'tinyint signed' => ['tinyint', false, 127],
            'tinyint unsigned' => ['tinyint', true, 255],
            'smallint signed' => ['smallint', false, 32767],
            'smallint unsigned' => ['smallint', true, 65535],
            'mediumint signed' => ['mediumint', false, 8388607],
            'int signed' => ['int', false, 2147483647],
            'int unsigned' => ['int', true, 4294967295],
            'bigint signed' => ['bigint', false, PHP_INT_MAX],
        ];
    }

    public function testIntegerValueIsTheRowNumber(): void
    {
        $this->assertSame(42, $this->value(Schemas::column('n', 'int', 'int', ['precision' => 10, 'scale' => 0]), 42));
    }

    public function testDecimalFitsItsPrecisionAndScale(): void
    {
        $column = Schemas::column('price', 'decimal', 'decimal(5,2)', ['precision' => 5, 'scale' => 2]);

        $this->assertSame('42.50', $this->value($column, 42));
        $this->assertSame('1.50', $this->value($column, 101));
        // 3 integer digits at most
        $this->assertMatchesRegularExpression('/^\d{1,3}\.\d{2}$/', $this->value($column, 99999));
    }

    public function testDecimalWithoutAFractionIsAnInteger(): void
    {
        $column = Schemas::column('n', 'decimal', 'decimal(6,0)', ['precision' => 6, 'scale' => 0]);

        $this->assertMatchesRegularExpression('/^\d{1,6}$/', $this->value($column, 77));
    }

    public function testDecimalWithOnlyFractionDigitsKeepsAZeroIntegerPart(): void
    {
        $column = Schemas::column('rate', 'decimal', 'decimal(2,2)', ['precision' => 2, 'scale' => 2]);

        $this->assertMatchesRegularExpression('/^0\.\d{2}$/', $this->value($column, 9));
    }

    public function testUniqueDecimalIsDistinctPerRow(): void
    {
        $column = Schemas::column('n', 'decimal', 'decimal(10,2)', ['precision' => 10, 'scale' => 2]);
        $table = new TableSchema('things', [$column], [], [['n']]);
        $values = [];
        for ($i = 1; $i <= 500; $i++) {
            $values[] = (new ValueFactory())->value($table, $column, $i);
        }

        $this->assertCount(500, array_unique($values));
    }

    public function testFloatHasAFraction(): void
    {
        $column = Schemas::column('ratio', 'double', 'double');

        $this->assertSame('3.5', (string) $this->value($column, 3));
    }

    public function testDatesAreBasePlusDays(): void
    {
        $date = Schemas::column('born', 'date');
        $dt = Schemas::column('seen', 'datetime');

        $this->assertSame('2026-01-01', $this->value($date, 1));
        $this->assertSame('2026-01-11', $this->value($date, 11));
        $this->assertSame('2026-01-02 00:00:00', $this->value($dt, 2));
    }

    public function testDatesStayInsideTheTimestampRange(): void
    {
        $column = Schemas::column('seen', 'timestamp');

        foreach ([1, 3000, 10000, 100000, 1_000_000] as $i) {
            $this->assertLessThan('2038-01-01', $this->value($column, $i));
        }
    }

    public function testUniqueDatetimeIsDistinctPerRow(): void
    {
        $column = Schemas::column('at', 'datetime');
        $table = new TableSchema('things', [$column], [], [['at']]);
        $values = [];
        for ($i = 1; $i <= 10000; $i++) {
            $values[] = (new ValueFactory())->value($table, $column, $i);
        }

        $this->assertCount(10000, array_unique($values));
    }

    public function testTimeAndYear(): void
    {
        $this->assertSame('00:00:05', $this->value(Schemas::column('t', 'time'), 6));
        $year = $this->value(Schemas::column('y', 'year'), 7);
        $this->assertGreaterThanOrEqual(1901, $year);
        $this->assertLessThanOrEqual(2155, $year);
    }

    public function testTimestampColumnsByNameUseTheBaseDate(): void
    {
        $created = Schemas::column('created_at', 'timestamp', 'timestamp', ['nullable' => true]);
        $updated = Schemas::column('updated_at', 'datetime', 'datetime', ['nullable' => true]);
        $deleted = Schemas::column('deleted_at', 'timestamp', 'timestamp', ['nullable' => true]);

        $this->assertSame('2026-01-01 00:00:00', $this->value($created, 9));
        $this->assertSame('2026-01-01 00:00:00', $this->value($updated, 9));
        $this->assertNull($this->value($deleted, 9));
    }

    public function testDeletedAtThatCannotBeNullFallsBackToTheBaseDate(): void
    {
        $deleted = Schemas::column('deleted_at', 'timestamp', 'timestamp', ['nullable' => false]);

        $this->assertSame('2026-01-01 00:00:00', $this->value($deleted, 1));
    }

    public function testBooleanAlternates(): void
    {
        $column = Schemas::column('is_public', 'tinyint', 'tinyint(1)', ['precision' => 3, 'scale' => 0]);

        $this->assertSame([1, 0, 1, 0], array_map(fn (int $i) => $this->value($column, $i), [1, 2, 3, 4]));
    }

    #[DataProvider('forcedFalseNames')]
    public function testPrivilegeAndHidingFlagsAreNeverSet(string $name): void
    {
        $column = Schemas::column($name, 'tinyint', 'tinyint(1)', ['precision' => 3, 'scale' => 0]);

        foreach (range(1, 6) as $i) {
            $this->assertSame(0, $this->value($column, $i), $name);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function forcedFalseNames(): array
    {
        return [
            'admin' => ['admin'],
            'is_admin' => ['is_admin'],
            'is_super_admin' => ['is_super_admin'],
            'super' => ['super'],
            'superuser' => ['superuser'],
            'deleted' => ['deleted'],
            'is_deleted' => ['is_deleted'],
            'archived' => ['archived'],
            'banned' => ['banned'],
            'disabled' => ['disabled'],
            'inactive' => ['inactive'],
            'blocked' => ['blocked'],
            'suspended' => ['suspended'],
        ];
    }

    #[DataProvider('ordinaryFlagNames')]
    public function testOrdinaryFlagsStillAlternate(string $name): void
    {
        $column = Schemas::column($name, 'tinyint', 'tinyint(1)', ['precision' => 3, 'scale' => 0]);

        $this->assertSame(1, $this->value($column, 1), $name);
    }

    /** @return array<string, array{0: string}> */
    public static function ordinaryFlagNames(): array
    {
        return ['is_active' => ['is_active'], 'banner' => ['banner'], 'public' => ['public'], 'urbane' => ['urbane']];
    }

    public function testEnumRotatesThroughItsValues(): void
    {
        $column = Schemas::column('kind', 'enum', "enum('a','b','c')", ['enumValues' => ['a', 'b', 'c']]);

        $this->assertSame(['a', 'b', 'c', 'a', 'b'], array_map(fn (int $i) => $this->value($column, $i), [1, 2, 3, 4, 5]));
    }

    public function testEnumSkipsValuesThatWouldHideTheRow(): void
    {
        $column = Schemas::column('state', 'enum', "enum('active','deleted','banned','archived','disabled','inactive','pending')", [
            'enumValues' => ['active', 'deleted', 'banned', 'archived', 'disabled', 'inactive', 'pending'],
        ]);
        $seen = array_unique(array_map(fn (int $i) => $this->value($column, $i), range(1, 20)));
        sort($seen);

        $this->assertSame(['active', 'pending'], $seen);
    }

    public function testEnumWithOnlyHidingValuesStillGetsAValue(): void
    {
        $column = Schemas::column('state', 'enum', "enum('deleted')", ['enumValues' => ['deleted']]);

        $this->assertSame('deleted', $this->value($column, 1));
    }

    public function testSetUsesASingleMember(): void
    {
        $column = Schemas::column('flags', 'set', "set('x','y')", ['enumValues' => ['x', 'y']]);

        $this->assertSame('x', $this->value($column, 1));
        $this->assertSame('y', $this->value($column, 2));
    }

    public function testJsonIsAnEmptyObject(): void
    {
        $this->assertSame('{}', $this->value(Schemas::column('meta', 'json'), 4));
    }

    public function testBlobAndBinaryAreShortAsciiAndRespectTheirLength(): void
    {
        $blob = $this->value(Schemas::column('data', 'blob'), 5);
        $binary = $this->value(Schemas::column('hash', 'binary', 'binary(8)', ['length' => 8]), 5);

        $this->assertNotSame('', $blob);
        $this->assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $blob);
        $this->assertLessThanOrEqual(8, strlen($binary));
        $this->assertNotSame('', $binary);
    }

    // -- which columns are written at all --

    public function testAutoIncrementAndGeneratedColumnsAreOmitted(): void
    {
        $factory = new ValueFactory();
        $table = $this->table(Schemas::id());

        $this->assertSame(ValueFactory::OMIT, $factory->usage($table, Schemas::id()));
        $generated = Schemas::column('slug', 'varchar', 'varchar(20)', ['length' => 20, 'generated' => true]);
        $this->assertSame(ValueFactory::OMIT, $factory->usage($this->table($generated), $generated));
    }

    public function testNotNullColumnsWithADefaultAreOmitted(): void
    {
        $column = Schemas::column('units', 'int', 'int', ['precision' => 10, 'scale' => 0, 'hasDefault' => true, 'default' => '0']);

        $this->assertSame(ValueFactory::OMIT, (new ValueFactory())->usage($this->table($column), $column));
    }

    public function testNullableColumnsWithANonNullDefaultAreOmitted(): void
    {
        $column = Schemas::column('state', 'varchar', 'varchar(10)', ['length' => 10, 'nullable' => true, 'hasDefault' => true, 'default' => 'new']);

        $this->assertSame(ValueFactory::OMIT, (new ValueFactory())->usage($this->table($column), $column));
    }

    public function testNullableColumnsWithoutADefaultGetAValue(): void
    {
        $column = Schemas::varchar('note', 50, true);

        $this->assertSame(ValueFactory::VALUE, (new ValueFactory())->usage($this->table($column), $column));
    }

    public function testAUniqueColumnIsWrittenEvenWhenItHasADefault(): void
    {
        $column = Schemas::column('code', 'varchar', 'varchar(10)', ['length' => 10, 'hasDefault' => true, 'default' => 'x']);
        $table = new TableSchema('things', [$column], [], [['code']]);

        $this->assertSame(ValueFactory::VALUE, (new ValueFactory())->usage($table, $column));
    }

    #[DataProvider('unsupportedTypes')]
    public function testUnsupportedTypesAreFlaggedWhenTheColumnIsRequired(string $type): void
    {
        $column = Schemas::column('shape', $type, $type);

        $this->assertSame(ValueFactory::UNSUPPORTED, (new ValueFactory())->usage($this->table($column), $column));
    }

    #[DataProvider('unsupportedTypes')]
    public function testUnsupportedTypesAreOmittedWhenTheColumnMayBeNull(string $type): void
    {
        $column = Schemas::column('shape', $type, $type, ['nullable' => true]);

        $this->assertSame(ValueFactory::OMIT, (new ValueFactory())->usage($this->table($column), $column));
    }

    // -- a secret that is optional is left empty: a value there switches a feature on --

    /** @return array<string, array{0: string, 1: string}> */
    public static function secretColumns(): array
    {
        return [
            'mfa_secret' => ['mfa_secret', 'varchar'],
            'two_factor_secret' => ['two_factor_secret', 'text'],
            'two_factor_recovery_codes' => ['two_factor_recovery_codes', 'text'],
            'google_2fa_secret' => ['google_2fa_secret', 'varchar'],
            'client_secret' => ['google_client_secret', 'varchar'],
            'plain secret' => ['secret', 'varchar'],
        ];
    }

    #[DataProvider('secretColumns')]
    public function testAnOptionalSecretColumnIsNull(string $name, string $type): void
    {
        $column = Schemas::column($name, $type, $type === 'varchar' ? 'varchar(255)' : $type, ['nullable' => true, 'length' => 255]);

        $this->assertNull($this->value($column, 3));
    }

    public function testARequiredSecretColumnStillGetsAValue(): void
    {
        // NULL is not allowed there, and the row would not be written
        $column = Schemas::varchar('secret', 100);

        $this->assertSame('things_secret_3', $this->value($column, 3));
    }

    public function testAColumnThatOnlyLooksLikeASecretIsNotEmptied(): void
    {
        foreach (['secretary', 'secretive', 'nonsecretion'] as $name) {
            $this->assertNotNull($this->value(Schemas::varchar($name, 100, true), 2), $name);
        }
    }

    // -- a time-zone column is a time zone --

    public function testAnTzColumnGetsUtc(): void
    {
        $this->assertSame('UTC', $this->value(Schemas::varchar('date_tz', 50), 4));
        $this->assertSame('UTC', $this->value(Schemas::varchar('start_date_tz', 50, true), 9));
    }

    public function testAnTzColumnIsUtcInTheRowsOfEveryNumber(): void
    {
        $column = Schemas::varchar('end_tz', 50, true);

        foreach ([1, 2, 1000] as $i) {
            $this->assertSame('UTC', $this->value($column, $i));
        }
    }

    public function testATzColumnThatCannotHoldAWordKeepsTheOrdinaryRule(): void
    {
        $this->assertSame(1, $this->value(Schemas::column('tz_offset_tz', 'int', 'int', ['precision' => 10]), 1));
        $this->assertNotSame('UTC', $this->value(Schemas::varchar('x_tz', 2), 1));
    }

    public function testNamesThatEndInTzOnlyAsPartOfAWordAreLeftAlone(): void
    {
        $this->assertNotSame('UTC', $this->value(Schemas::varchar('blitz', 50), 1));
        $this->assertNotSame('UTC', $this->value(Schemas::varchar('tz_note', 50), 1));
    }

    public function testAProfileConstantStillWinsOverTheNameRules(): void
    {
        $generated = $this->generateOne(Schemas::varchar('date_tz', 50), ['date_tz' => 'Europe/Paris']);

        $this->assertSame('Europe/Paris', $generated);
    }

    /** @param array<string, mixed> $constants */
    private function generateOne(ColumnSchema $column, array $constants): mixed
    {
        $schema = new \PeakSim\Runtime\Data\DatabaseSchema([new TableSchema('events', [Schemas::id(), $column], ['id'])]);
        $sink = new RecordingSink();
        (new \PeakSim\Runtime\Data\Generator())->generateInto($schema, $sink, \PeakSim\Runtime\Data\ScalePlan::global(2, 2), ['values' => ['events' => $constants]]);

        return $sink->assoc('events')[0][$column->name];
    }

    public function testGeneratedRowsCarryTheNullSecretAndTheUtcZone(): void
    {
        $schema = new \PeakSim\Runtime\Data\DatabaseSchema([new TableSchema('users', [
            Schemas::id(),
            Schemas::varchar('name'),
            Schemas::varchar('mfa_secret', 50, true),
            Schemas::varchar('joined_tz', 50, true),
        ], ['id'])]);
        $sink = new RecordingSink();
        (new \PeakSim\Runtime\Data\Generator())->generateInto($schema, $sink, \PeakSim\Runtime\Data\ScalePlan::global(3, 3));

        foreach ($sink->assoc('users') as $row) {
            $this->assertNull($row['mfa_secret']);
            $this->assertSame('UTC', $row['joined_tz']);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function unsupportedTypes(): array
    {
        return [
            'geometry' => ['geometry'],
            'point' => ['point'],
            'polygon' => ['polygon'],
            'linestring' => ['linestring'],
            'multipoint' => ['multipoint'],
            'geometrycollection' => ['geometrycollection'],
            'bit' => ['bit'],
            'unknown' => ['somethingnew'],
        ];
    }
}
