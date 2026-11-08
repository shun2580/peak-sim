<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Explain\PlanSummary;
use PHPUnit\Framework\TestCase;

final class PlanSummaryTest extends TestCase
{
    private const SINGLE = '{"query_block":{"select_id":1,"cost_info":{"query_cost":"1.10"},"table":{"table_name":"users","access_type":"const","possible_keys":["PRIMARY"],"key":"PRIMARY","used_key_parts":["id"],"key_length":"8","ref":["const"],"rows_examined_per_scan":1,"rows_produced_per_join":1,"filtered":"100.00"}}}';

    private const JOIN = '{"query_block":{"select_id":1,"ordering_operation":{"using_filesort":true,"nested_loop":[{"table":{"table_name":"orders","access_type":"ref","key":"orders_customer_id_index","used_key_parts":["customer_id"],"rows_examined_per_scan":40,"rows_produced_per_join":40,"filtered":"100.00"}},{"table":{"table_name":"customers","access_type":"eq_ref","key":"PRIMARY","used_key_parts":["id"],"rows_examined_per_scan":1,"rows_produced_per_join":40,"filtered":"100.00"}}]}}}';

    private const DELETE_SCAN = '{"query_block":{"select_id":1,"table":{"delete":true,"table_name":"references","access_type":"ALL","rows_examined_per_scan":98213,"filtered":"1.00"}}}';

    private const INSERT = '{"query_block":{"select_id":1,"table":{"insert":true,"table_name":"activities"}}}';

    private const DERIVED = '{"query_block":{"select_id":1,"nested_loop":[{"table":{"table_name":"<derived2>","access_type":"ALL","rows_examined_per_scan":10,"rows_produced_per_join":10,"filtered":"100.00","materialized_from_subquery":{"using_temporary_table":true,"dependent":false,"cacheable":true,"query_block":{"select_id":2,"table":{"table_name":"items","access_type":"index","key":"items_a","rows_examined_per_scan":900,"rows_produced_per_join":900,"filtered":"100.00"}}}}}]}}';

    /** @return array<string, mixed> */
    private static function plan(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testASingleTableIsSummarisedWithItsOwnWords(): void
    {
        $s = PlanSummary::summarize(self::plan(self::SINGLE));

        $this->assertCount(1, $s['tables']);
        $this->assertSame('users', $s['tables'][0]['table']);
        $this->assertSame('const', $s['tables'][0]['access_type']);
        $this->assertSame('PRIMARY', $s['tables'][0]['key']);
        $this->assertSame(['id'], $s['tables'][0]['used_key_parts']);
        $this->assertSame(1, $s['tables'][0]['rows_examined_per_scan']);
        $this->assertSame(100.0, $s['tables'][0]['filtered']);
        $this->assertSame('users|const|PRIMARY', $s['signature']);
    }

    public function testAJoinKeepsTheTablesInTheOrderTheServerNamesThemAndFindsThemUnderOrdering(): void
    {
        $s = PlanSummary::summarize(self::plan(self::JOIN));

        $this->assertSame('orders|ref|orders_customer_id_index;customers|eq_ref|PRIMARY', $s['signature']);
        $this->assertSame(41, $s['scan_rows_sum']);
    }

    public function testAFullScanOfADeleteHasNoKey(): void
    {
        $s = PlanSummary::summarize(self::plan(self::DELETE_SCAN));

        $this->assertSame('references|ALL|-', $s['signature']);
        $this->assertNull($s['tables'][0]['key']);
        $this->assertSame(98213, $s['tables'][0]['rows_examined_per_scan']);
        $this->assertSame(1.0, $s['tables'][0]['filtered']);
        $this->assertNull($s['tables'][0]['rows_produced_per_join']);
    }

    public function testAnInsertTargetHasNothingMadeUp(): void
    {
        $s = PlanSummary::summarize(self::plan(self::INSERT));

        $this->assertSame('activities|-|-', $s['signature']);
        $this->assertTrue($s['tables'][0]['insert']);
        $this->assertNull($s['tables'][0]['rows_examined_per_scan']);
        $this->assertNull($s['scan_rows_sum'], 'no number was given, so none is added up');
    }

    public function testTheAllTheServerWritesForAnInsertTargetIsNotAnAccessTypeOfARead(): void
    {
        // as MySQL 8.4 gives it for INSERT ... SELECT: the target says ALL, and the read of the SELECT is under insert_from
        $plan = self::plan('{"query_block":{"select_id":1,"table":{"insert":true,"select_id":1,"table_name":"t","access_type":"ALL"},"insert_from":{"buffer_result":{"using_temporary_table":true,"table":{"table_name":"t","access_type":"range","key":"t_a","rows_examined_per_scan":2,"filtered":"100.00"}}}}}');

        $s = PlanSummary::summarize($plan);

        $this->assertSame('t|-|-;t|range|t_a', $s['signature']);
        $this->assertSame(2, $s['scan_rows_sum']);
    }

    public function testTablesInsideADerivedTableAreFound(): void
    {
        $s = PlanSummary::summarize(self::plan(self::DERIVED));

        $this->assertSame('<derived2>|ALL|-;items|index|items_a', $s['signature']);
    }

    public function testAMovedRowEstimateIsTheSamePlan(): void
    {
        $a = self::plan(self::SINGLE);
        $b = self::plan(str_replace('"rows_examined_per_scan":1,', '"rows_examined_per_scan":5,', self::SINGLE));

        $this->assertSame(PlanSummary::summarize($a)['signature'], PlanSummary::summarize($b)['signature']);
    }

    public function testAPlanWithoutTablesHasAnEmptySignature(): void
    {
        $s = PlanSummary::summarize(['query_block' => ['select_id' => 1, 'message' => 'No tables used']]);

        $this->assertSame([], $s['tables']);
        $this->assertSame('', $s['signature']);
        $this->assertNull($s['scan_rows_sum']);
    }
}
