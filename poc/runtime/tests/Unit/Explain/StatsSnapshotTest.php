<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Explain\StatsCheck;
use PeakSim\Runtime\Explain\StatsSnapshot;
use PHPUnit\Framework\TestCase;

final class StatsSnapshotTest extends TestCase
{
    /** @param array<string, int> $samples index name => sample_size */
    private static function copy(string $time, string $options, array $samples, int $rows = 1000, string $updated = '2026-11-09 01:00:00'): string
    {
        $text = "# at $time\n";
        $text .= "innodb_table_stats\tt\t$rows\t10\t5\t$updated\n";
        foreach ($samples as $index => $size) {
            $text .= "innodb_index_stats\tt\t$index\tn_diff_pfx01\t900\t$size\t$updated\n";
            $text .= "innodb_index_stats\tt\t$index\tn_leaf_pages\t50\t\t$updated\n";
        }
        $text .= "table_options\tt\t$options\n";

        return $text;
    }

    public function testTheTimeOfTheCopyIsNotPartOfWhatIsCompared(): void
    {
        $a = StatsSnapshot::parse(self::copy('01:00:01', 'stats_auto_recalc=0', ['PRIMARY' => 4]));
        $b = StatsSnapshot::parse(self::copy('01:00:59', 'stats_auto_recalc=0', ['PRIMARY' => 4]));

        $this->assertTrue($a->sameAs($b));
        $this->assertSame(['changed' => 0, 'added' => 0, 'removed' => 0], $a->differences($b));
    }

    public function testItCountsWhatChangedAppearedAndWent(): void
    {
        $a = StatsSnapshot::parse(self::copy('x', 'o', ['PRIMARY' => 4, 'k1' => 4]));
        $b = StatsSnapshot::parse(self::copy('x', 'o', ['PRIMARY' => 20, 'k2' => 4], 1200));

        $diff = $a->differences($b);
        $this->assertFalse($a->sameAs($b));
        $this->assertGreaterThan(0, $diff['changed']);
        $this->assertGreaterThan(0, $diff['added']);
        $this->assertGreaterThan(0, $diff['removed']);
    }

    public function testItReadsTheOptionsTheSampleSizeAndTheLatestUpdate(): void
    {
        $s = StatsSnapshot::parse(self::copy('x', 'stats_sample_pages=4 stats_auto_recalc=0', ['PRIMARY' => 4, 'k1' => 3], 1000, '2026-11-09 01:02:03'));

        $this->assertSame('stats_sample_pages=4 stats_auto_recalc=0', $s->createOptions('t'));
        $this->assertNull($s->createOptions('ghost'));
        $this->assertSame(4, $s->maxSampleSize('t'));
        $this->assertSame('2026-11-09 01:02:03', $s->lastUpdate('t'));
        $this->assertSame(['1000', '10', '5', '2026-11-09 01:02:03'], $s->tableStats('t'));
    }

    public function testAnEmptyCopyIsEmpty(): void
    {
        $this->assertTrue(StatsSnapshot::parse("# at x\n")->isEmpty());
    }

    public function testAStateThatWasMadeAsIntendedIsOk(): void
    {
        $before = StatsSnapshot::parse(self::copy('1', 'stats_auto_recalc=0', ['PRIMARY' => 20], 1000, '2026-11-09 01:00:00'));
        $after = StatsSnapshot::parse(self::copy('2', 'stats_auto_recalc=0 stats_sample_pages=4', ['PRIMARY' => 4], 1010, '2026-11-09 01:00:05'));
        $explained = StatsSnapshot::parse(self::copy('3', 'stats_auto_recalc=0 stats_sample_pages=4', ['PRIMARY' => 4], 1010, '2026-11-09 01:00:05'));

        $check = StatsCheck::state($before, $after, $explained, ['t'], 4);

        $this->assertTrue($check['ok']);
        $this->assertTrue($check['options_ok']);
        $this->assertTrue($check['unchanged_during_explain']);
        $this->assertSame(1, $check['restated_tables']);
    }

    public function testStatisticsThatMovedDuringTheExplainsAreNotOk(): void
    {
        $before = StatsSnapshot::parse(self::copy('1', 'stats_auto_recalc=0 stats_sample_pages=20', ['PRIMARY' => 20]));
        $after = $before;
        $explained = StatsSnapshot::parse(self::copy('3', 'stats_auto_recalc=0 stats_sample_pages=20', ['PRIMARY' => 20], 1500));

        $check = StatsCheck::state($before, $after, $explained, ['t'], 20);

        $this->assertFalse($check['ok']);
        $this->assertFalse($check['unchanged_during_explain']);
        $this->assertSame(1, $check['changed_during_explain']['changed']);
    }

    public function testASamplePageOptionThatWasNotSetIsNotOk(): void
    {
        $s = StatsSnapshot::parse(self::copy('1', 'stats_auto_recalc=0', ['PRIMARY' => 4]));

        $check = StatsCheck::state($s, $s, $s, ['t'], 4);

        $this->assertFalse($check['options_ok']);
        $this->assertSame(['t'], $check['options_wrong_on']);
        $this->assertFalse($check['ok']);
    }

    public function testAnAutoRecalcThatIsStillOnIsNotOk(): void
    {
        $s = StatsSnapshot::parse(self::copy('1', 'stats_sample_pages=4', ['PRIMARY' => 4]));

        $this->assertFalse(StatsCheck::state($s, $s, $s, ['t'], 4)['options_ok']);
    }

    public function testASampleSizeSlightlyAboveThePageCountIsRecordedNotJudged(): void
    {
        $s = StatsSnapshot::parse(self::copy('1', 'stats_auto_recalc=0 stats_sample_pages=4', ['PRIMARY' => 5]));

        $check = StatsCheck::state($s, $s, $s, ['t'], 4);

        $this->assertSame(['t' => 5], $check['max_sample_size']);
        $this->assertTrue($check['ok'], 'InnoDB counts pages of more than one level, so 5 for 4 pages is what the server does');
    }

    public function testTheOrderingOfTheStatesAcrossTheTablesIsJudged(): void
    {
        $ordered = StatsCheck::ordering(['low' => ['a' => 5, 'b' => 1], 'default' => ['a' => 12, 'b' => 1], 'high' => ['a' => 12, 'b' => 1]]);
        $this->assertTrue($ordered['ok']);
        $this->assertSame(2, $ordered['tables_compared']);
        $this->assertSame(1, $ordered['lower_with_fewer_pages']);

        $upside = StatsCheck::ordering(['low' => ['a' => 20], 'default' => ['a' => 12], 'high' => ['a' => 12]]);
        $this->assertFalse($upside['ok']);
        $this->assertSame(['a'], $upside['out_of_order_on']);

        $this->assertFalse(StatsCheck::ordering(['low' => [], 'default' => [], 'high' => []])['ok']);
    }

    public function testNoRelevantTableIsNotOk(): void
    {
        $s = StatsSnapshot::parse(self::copy('1', 'stats_auto_recalc=0 stats_sample_pages=4', ['PRIMARY' => 4]));

        $this->assertFalse(StatsCheck::state($s, $s, $s, [], 4)['ok']);
    }
}
