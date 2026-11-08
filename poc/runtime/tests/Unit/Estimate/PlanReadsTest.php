<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\PlanReads;
use PeakSim\Runtime\Estimate\UnrecognisedPlan;
use PHPUnit\Framework\TestCase;

/** Expected values are worked out by hand from the walk in docs/research/explain-estimate.md, section 2, plus c(access_type). */
final class PlanReadsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function table(string $access, int|float $rows, int|float|null $produced = null, array $extra = []): array
    {
        return ['table_name' => 't', 'access_type' => $access, 'rows_examined_per_scan' => $rows, 'rows_produced_per_join' => $produced ?? $rows, 'filtered' => '100.00'] + $extra;
    }

    public function test_single_full_scan_reads_rows_plus_two(): void
    {
        $this->assertEqualsWithDelta(1002.0, PlanReads::perExecution(['query_block' => ['table' => self::table('ALL', 1000)]]), 1e-9);
    }

    public function test_single_ref_reads_rows_plus_one_and_const_adds_nothing(): void
    {
        $this->assertEqualsWithDelta(6.0, PlanReads::perExecution(['query_block' => ['table' => self::table('ref', 5)]]), 1e-9);
        $this->assertEqualsWithDelta(1.0, PlanReads::perExecution(['query_block' => ['table' => self::table('const', 1)]]), 1e-9);
    }

    public function test_second_table_is_scanned_once_per_row_the_first_produces(): void
    {
        // t1: 100 + 1 x 2 = 102;  t2: 100 scans x 3 rows + 100 scans x 1 = 400
        $plan = ['query_block' => ['nested_loop' => [['table' => self::table('ALL', 100)], ['table' => self::table('ref', 3, 300)]]]];
        $this->assertEqualsWithDelta(502.0, PlanReads::perExecution($plan), 1e-9);
    }

    public function test_a_table_with_a_join_buffer_is_scanned_once(): void
    {
        $plan = ['query_block' => ['nested_loop' => [['table' => self::table('ALL', 100)], ['table' => self::table('ALL', 50, 5000, ['using_join_buffer' => 'hash join'])]]]];
        $this->assertEqualsWithDelta(154.0, PlanReads::perExecution($plan), 1e-9);
    }

    public function test_dependent_select_list_subquery_runs_once_per_row_of_the_outer_block(): void
    {
        // outer: 10 + 2 = 12;  subquery run 10 times: 10 x 2 + 10 x 1 = 30
        $plan = ['query_block' => [
            'table' => self::table('ALL', 10),
            'select_list_subqueries' => [['dependent' => true, 'cacheable' => false, 'query_block' => ['table' => self::table('ref', 2)]]],
        ]];
        $this->assertEqualsWithDelta(42.0, PlanReads::perExecution($plan), 1e-9);
    }

    public function test_cacheable_subquery_runs_once(): void
    {
        $plan = ['query_block' => [
            'table' => self::table('ALL', 10),
            'select_list_subqueries' => [['dependent' => false, 'cacheable' => true, 'query_block' => ['table' => self::table('ref', 2)]]],
        ]];
        $this->assertEqualsWithDelta(15.0, PlanReads::perExecution($plan), 1e-9);
    }

    public function test_union_adds_the_reads_of_each_specification(): void
    {
        $plan = ['query_block' => ['union_result' => ['query_specifications' => [
            ['query_block' => ['table' => self::table('const', 1)]],
            ['query_block' => ['table' => self::table('ref', 4)]],
        ]]]];
        $this->assertEqualsWithDelta(6.0, PlanReads::perExecution($plan), 1e-9);
    }

    public function test_a_block_with_only_a_message_reads_nothing(): void
    {
        $this->assertSame(0.0, PlanReads::perExecution(['query_block' => ['message' => 'no matching row in const table']]));
    }

    public function test_the_target_of_an_insert_reads_nothing(): void
    {
        $plan = ['query_block' => ['table' => ['table_name' => 't', 'access_type' => 'ALL', 'insert' => true]]];
        $this->assertSame(0.0, PlanReads::perExecution($plan));
    }

    public function test_an_unknown_access_type_is_not_read_as_zero(): void
    {
        $this->expectException(UnrecognisedPlan::class);
        PlanReads::perExecution(['query_block' => ['table' => self::table('mystery', 5)]]);
    }

    public function test_a_table_without_a_row_estimate_is_not_read_as_zero(): void
    {
        $this->expectException(UnrecognisedPlan::class);
        PlanReads::perExecution(['query_block' => ['table' => ['table_name' => 't', 'access_type' => 'ALL']]]);
    }
}
