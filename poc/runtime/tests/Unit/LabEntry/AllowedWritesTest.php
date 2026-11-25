<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabEntry;

use PeakSim\Runtime\LabEntry\AllowedWrites;
use PeakSim\Runtime\LabEntry\ViewsOnlyWrites;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The list of tables an app's lab may write (AllowedWrites), and the entry's statement rule over it. The lab's own scripts read the same file
 * (poc/lab/bin/check-writes, poc/lab/bin/check-tables); poc/lab/tests/allowed-writes.sh holds the three readers together.
 */
final class AllowedWritesTest extends TestCase
{
    private const DB = 'peak_sim_measure_abc123_lab_10000';

    public function testAListIsNamesOneToALineWithCommentsAndBlankLines(): void
    {
        $text = "# the tables the lab may write\n\nsessions\n  visits  # the visit record\r\nsessions\n\t\n#last\n";
        self::assertSame(['sessions', 'visits'], AllowedWrites::parse($text));
    }

    public function testAFileThatNamesNothingAllowsNothing(): void
    {
        self::assertSame([], AllowedWrites::parse("# nothing yet\n\n"));
        self::assertSame([], AllowedWrites::parse(''));
    }

    /** @return iterable<string, array{string}> */
    public static function notNames(): iterable
    {
        yield 'a schema-qualified name' => ["sessions\nother_db.visits\n"];
        yield 'a quoted name' => ['`sessions`'];
        yield 'two names on a line' => ['sessions visits'];
        yield 'a list with a comma' => ['sessions,visits'];
        yield 'a star' => ['*'];
        yield 'a dash' => ['my-table'];
        yield 'a name with a space inside' => ['my table'];
    }

    #[DataProvider('notNames')]
    public function testALineThatIsNotATableNameIsAnError(string $text): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AllowedWrites::parse($text);
    }

    public function testTheEnvironmentNamesAFileOrNone(): void
    {
        self::assertSame(['views'], AllowedWrites::fromEnv([]));
        self::assertSame(['views'], AllowedWrites::fromEnv(['PEAK_LAB_ALLOWED_WRITES_FILE' => '']));

        $file = tempnam(sys_get_temp_dir(), 'allowed-writes-');
        self::assertIsString($file);
        try {
            file_put_contents($file, "sessions\nvisits\n");
            self::assertSame(['sessions', 'visits'], AllowedWrites::fromEnv(['PEAK_LAB_ALLOWED_WRITES_FILE' => $file]));
        } finally {
            unlink($file);
        }
    }

    /** A list that is named and empty is "no write at all": it never falls to `views` (the Bagisto lab's list is empty). */
    public function testANamedEmptyFileIsNotTheDefault(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'allowed-writes-');
        self::assertIsString($file);
        try {
            file_put_contents($file, "# the selected routes write no table\n");
            $tables = AllowedWrites::fromEnv(['PEAK_LAB_ALLOWED_WRITES_FILE' => $file]);
            self::assertSame([], $tables);
            self::assertNotNull(ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, 'update `views` set `views` = 1', $tables));
            self::assertNotNull(ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, 'insert into `sessions` (`id`) values (?)', $tables));
            self::assertNull(ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, 'select * from `products`', $tables));
        } finally {
            unlink($file);
        }
    }

    public function testAFileThatIsNamedAndCannotBeReadFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AllowedWrites::fromEnv(['PEAK_LAB_ALLOWED_WRITES_FILE' => '/nonexistent/allowed-write-tables.txt']);
    }

    /** @return iterable<string, array{string}> */
    public static function allowedForTheList(): iterable
    {
        yield 'an insert into a table of the list' => ['insert into `sessions` (`id`, `payload`) values (?, ?)'];
        yield 'an update of another table of the list' => ['update `visits` set `n` = `n` + 1 where `id` = ?'];
        yield 'with the schema of the measurement database' => ['update `' . self::DB . '`.`visits` set `n` = 1'];
        yield 'a read of a table that is not in the list' => ['select * from `products` where `id` = ?'];
        yield 'a read of a table that is in it' => ['select * from `sessions` where `id` = ?'];
        yield 'an insert that reads another table' => ['insert into `visits` (`n`) select `id` from `products`'];
    }

    #[DataProvider('allowedForTheList')]
    public function testAllowedForTheList(string $sql): void
    {
        self::assertNull(ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, $sql, ['sessions', 'visits']));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedForTheList(): iterable
    {
        yield 'a write to a table of the N tables' => ['update `products` set `name` = ? where `id` = ?'];
        yield 'a write to views, which is not in this list' => ['update `views` set `views` = 1'];
        yield 'an insert into a table whose name starts with an allowed one' => ['insert into `sessions_archive` (`a`) values (?)'];
        yield 'an update of a table whose name starts with an allowed one' => ['update `visits_by_day` set `n` = 1'];
        yield 'a delete from an allowed table (the entry allows the insert and the update only)' => ['delete from `sessions` where `id` = ?'];
        yield 'a replace into an allowed table' => ['replace into `sessions` (`id`) values (?)'];
        yield 'a multi-table update that names an allowed table first' => ['update `sessions` join `products` on 1 = 1 set `products`.`name` = ?'];
        yield 'a multi-table update with a comma' => ['update `sessions`, `products` set `products`.`name` = ?'];
        yield 'an insert into a table of another schema' => ['insert into `other_db`.`sessions` (`id`) values (?)'];
        yield 'a write inside a WITH' => ['with `a` as (select 1) update `products` set `name` = ?'];
        yield 'two statements, the second outside the list' => ['update `sessions` set `a` = 1; update `products` set `name` = ?'];
        yield 'an executable comment' => ['/*!50000 update `sessions` set `a` = 1 */'];
        yield 'a truncate' => ['truncate table `sessions`'];
        yield 'a commit' => ['commit'];
    }

    #[DataProvider('refusedForTheList')]
    public function testRefusedForTheList(string $sql): void
    {
        $reason = ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, $sql, ['sessions', 'visits']);
        self::assertNotNull($reason, $sql);
    }

    public function testTheReasonSaysTheListWasTheRuleAndNotViews(): void
    {
        $reason = ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, 'update `products` set `name` = ?', ['sessions']);
        self::assertStringStartsWith('write_outside_allowed_tables: ', (string) $reason);
        $reason = ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, 'update `products` set `name` = ?');
        self::assertStringStartsWith('write_outside_views: ', (string) $reason);
    }

    public function testAnEmptyListRefusesEveryWrite(): void
    {
        foreach (['insert into `views` (`a`) values (?)', 'update `sessions` set `a` = 1'] as $sql) {
            self::assertNotNull(ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, $sql, []), $sql);
        }
        self::assertNull(ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, 'select 1', []));
    }

    public function testOtherConnectionsAreStillRefusedWhateverTheList(): void
    {
        self::assertStringStartsWith('other_connection', (string) ViewsOnlyWrites::violation('other', 'mysql', self::DB, 'update `sessions` set `a` = 1', ['sessions']));
    }

    public function testTheDefaultListIsViewsAlone(): void
    {
        self::assertSame(['views'], AllowedWrites::DEFAULT);
        self::assertNull(ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, 'update `views` set `views` = 1'));
        self::assertNotNull(ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, 'update `sessions` set `a` = 1'));
    }
}
