<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Model;

use PeakSim\Model\AssumptionCode;
use PeakSim\Model\GapCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The code sets are frozen (design sections 6.1 and 6.2). Adding one is a contract change. */
final class ContractCodesTest extends TestCase
{
    private const GAP_CODES = [
        'UNRESOLVED_CALL', 'VENDOR_CALL_NOT_MODELLED', 'VENDOR_PARENT', 'METHOD_NOT_FOUND', 'TRAIT_CONFLICT',
        'DYNAMIC_METHOD_NAME', 'DYNAMIC_CLASS_NAME', 'NEW_UNKNOWN_CLASS', 'UNKNOWN_BUILDER_METHOD',
        'UNKNOWN_COLLECTION_METHOD', 'LOOP_SOURCE_UNKNOWN', 'LOOP_BOUND_UNKNOWN', 'GENERATOR_NOT_ANALYZED',
        'NO_BINDING', 'UNRESOLVABLE_BINDING', 'BINDING_DECORATED', 'DEPTH_LIMIT', 'RECURSION', 'BUDGET_EXCEEDED',
        'UNION_TOO_LARGE', 'MODEL_INFO_UNAVAILABLE', 'MODEL_OBSERVER_NOT_ANALYZED', 'RELATION_TARGET_UNKNOWN',
        'VIEW_NOT_FOUND', 'BLADE_UNSUPPORTED', 'PARSE_FAILED', 'ACCESSOR_NOT_ANALYZED', 'RESOURCE_NOT_ANALYZED',
        'CACHE_VALUE_SIZE_UNKNOWN', 'CLOSURE_ROUTE', 'MIDDLEWARE_APP_CLASS', 'WITH_CYCLE',
        'MODEL_PROPERTY_UNRESOLVED',
    ];

    private const ASSUMPTION_CODES = [
        'WORST_CASE_OF_CANDIDATES', 'BRANCH_WORST_CASE', 'RELATION_INFERRED', 'DRIVER_ASSUMED',
        'CACHE_DRIVER_SERIALIZING', 'SQL_NOT_PARSED', 'TABLE_NAME_UNKNOWN', 'ACCUMULATION_HEURISTIC',
        'FIND_ARGUMENT_ASSUMED_SCALAR',
    ];

    public function testGapCodeSetIsExactlyTheFrozenList(): void
    {
        $actual = array_map(static fn (GapCode $c): string => $c->name, GapCode::cases());

        self::assertSame(self::GAP_CODES, $actual);
    }

    public function testAssumptionCodeSetIsExactlyTheFrozenList(): void
    {
        $actual = array_map(static fn (AssumptionCode $c): string => $c->name, AssumptionCode::cases());

        self::assertSame(self::ASSUMPTION_CODES, $actual);
    }

    /** @return iterable<string, array{string}> */
    public static function gapNames(): iterable
    {
        foreach (self::GAP_CODES as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('gapNames')]
    public function testGapCodeValueEqualsItsName(string $name): void
    {
        self::assertSame($name, GapCode::from($name)->value);
        self::assertSame($name, constant(GapCode::class . '::' . $name)->name);
    }

    public function testCodeValuesAreUniqueAcrossEachEnum(): void
    {
        $gap = array_map(static fn (GapCode $c): string => $c->value, GapCode::cases());
        $assumption = array_map(static fn (AssumptionCode $c): string => $c->value, AssumptionCode::cases());

        self::assertSame($gap, array_values(array_unique($gap)));
        self::assertSame($assumption, array_values(array_unique($assumption)));
    }
}
