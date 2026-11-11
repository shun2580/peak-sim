<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabLoad;

use PeakSim\Runtime\LabLoad\Ladder;
use PHPUnit\Framework\TestCase;

/**
 * The records of the first backtest (docs/research/lab-data/ladder/, 47 steps of t-1 .. t-5) are made again from the same inputs with
 * today's code. Adding the limits and the status of each route must not change anything a record already held: only the two new
 * fields are added, and they are null when nothing was given for them.
 */
final class LadderRecordCompatTest extends TestCase
{
    private const NEW_FIELDS = ['settings', 'http_status_by_route'];

    /** @return list<string> */
    private static function recordFiles(): array
    {
        $files = glob(__DIR__ . '/../../../../../docs/research/lab-data/ladder/t-*/rung-*/record.json') ?: [];
        sort($files);

        return $files;
    }

    public function test_the_records_of_the_first_backtest_are_made_again_unchanged_but_for_the_two_new_null_fields(): void
    {
        $files = self::recordFiles();
        $this->assertCount(47, $files, 'the records of the first backtest are the fixture; if they moved, move this test with them');

        foreach ($files as $file) {
            $record = json_decode((string) file_get_contents($file), true);
            $run = json_decode((string) file_get_contents(dirname($file) . '/run.json'), true);
            $meta = ['scenario' => $record['scenario'], 'scale' => $record['scale'], 'rung' => $record['rung'], 'started_at' => $record['started_at'], 'ended_at' => $record['ended_at']];
            if ($record['prediction'] !== null) {
                $meta['prediction'] = $record['prediction'];
            }
            $again = Ladder::record($meta, $run);

            foreach (self::NEW_FIELDS as $field) {
                $this->assertArrayHasKey($field, $again);
                $this->assertNull($again[$field], basename(dirname($file)) . ": $field has nothing given, so it is null");
            }
            $kept = array_diff_key($again, array_flip(self::NEW_FIELDS));
            $this->assertEquals($record, $kept, 'the record of ' . basename(dirname(dirname($file))) . '/' . basename(dirname($file)) . ' changed');
        }
    }

    public function test_the_first_set_and_its_order_are_what_the_first_backtest_used(): void
    {
        $seen = [];
        foreach (self::recordFiles() as $file) {
            $r = json_decode((string) file_get_contents($file), true);
            $seen[$r['scenario']] = [$r['scale'], $r['mix']];
        }
        ksort($seen);
        $today = [];
        foreach (['t-1', 't-2', 't-3', 't-4', 't-5'] as $id) {
            $s = Ladder::scenario($id);
            $today[$id] = [$s['scale'], $s['mix']];
        }
        $this->assertSame($seen, $today);
    }
}
