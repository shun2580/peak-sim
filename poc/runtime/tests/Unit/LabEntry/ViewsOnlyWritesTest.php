<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\LabEntry;

use PeakSim\Runtime\LabEntry\ViewsOnlyWrites;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ViewsOnlyWritesTest extends TestCase
{
    private const DB = 'peak_sim_measure_abc123_lab_10000';

    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'a read' => ['select * from `entities` where `id` = ? limit 1'];
        yield 'a locking read' => ['select * from `views` where `id` = ? for update'];
        yield 'what the app sends to insert a view counter' => ['insert into `views` (`user_id`, `views`, `viewable_id`, `viewable_type`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?, ?)'];
        yield 'what the app sends to update it' => ['update `views` set `views` = ?, `updated_at` = ? where `id` = ?'];
        yield 'insert, upper case' => ['INSERT INTO views (user_id) VALUES (1)'];
        yield 'insert with a schema of the measurement database' => ['insert into `' . self::DB . '`.`views` (`a`) values (?)'];
        yield 'update with a schema of the measurement database' => ['update ' . self::DB . '.views set views = 2'];
        yield 'update with a comment in front' => ["/* the app */ update `views` set `views` = 1\nwhere id = 1"];
        yield 'insert that reads another table' => ['insert into `views` (`a`) select `id` from `entities`'];
        yield 'a session variable' => ['set @a = 1'];
        yield 'a session setting' => ['set session sql_mode = ""'];
        yield 'two statements, both allowed' => ['update `views` set `views` = 1 where id = 1; select 1'];
    }

    #[DataProvider('allowed')]
    public function testAllowed(string $sql): void
    {
        self::assertNull(ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, $sql));
    }

    /** @return iterable<string, array{string, string}> statement, the start of the reason */
    public static function refused(): iterable
    {
        yield 'update of another table' => ['update `entities` set `name` = ? where `id` = ?', 'write_outside_views'];
        yield 'insert into another table' => ['insert into `activities` (`type`) values (?)', 'write_outside_views'];
        yield 'delete from another table' => ['delete from `sessions` where `id` = ?', 'write_outside_views'];
        yield 'delete from views (only an insert and an update are allowed)' => ['delete from `views` where `id` = ?', 'write_outside_views'];
        yield 'replace into views' => ['replace into `views` (`id`) values (1)', 'write_outside_views'];
        yield 'update of views and another table' => ['update `views`, `entities` set `views`.`views` = 1, `entities`.`name` = ?', 'write_outside_views'];
        yield 'update of views joined to another table' => ['update `views` inner join `entities` on 1 set `entities`.`name` = ?', 'write_outside_views'];
        yield 'update of views under an alias' => ['update `views` as v set v.`views` = 1', 'write_outside_views'];
        yield 'a table that only starts with views' => ['update `views_archive` set `views` = 1', 'write_outside_views'];
        yield 'a table named views of another schema' => ['update `other_db`.`views` set `views` = 1', 'write_outside_views'];
        yield 'the name is case sensitive' => ['update `Views` set `views` = 1', 'write_outside_views'];
        yield 'a write behind an allowed statement' => ['update `views` set `views` = 1 where id = 1; update `entities` set `name` = ?', 'write_outside_views'];
        yield 'a write behind a comment' => ['/* views */ update `entities` set `name` = ?', 'write_outside_views'];
        yield 'a write inside a WITH' => ['with a as (select 1) update `entities` set `name` = ?', 'write_outside_views'];
        yield 'a write inside a WITH, in parentheses' => ['(with a as (select 1) delete from `views`)', 'write_outside_views'];
        yield 'select into a file' => ["select * from `entities` into outfile '/tmp/x'", 'file_write'];
        yield 'select into a dump file' => ["select 1 into dumpfile '/tmp/x'", 'file_write'];
        yield 'a global setting' => ['set global max_connections = 1', 'global_setting'];
        yield 'a persisted global setting' => ['set persist max_connections = 1', 'global_setting'];
        yield 'a global setting by name' => ['set @@global.max_connections = 1', 'global_setting'];
        yield 'a commit' => ['commit', 'transaction_control'];
        yield 'begin' => ['begin', 'transaction_control'];
        yield 'autocommit' => ['set autocommit = 0', 'transaction_control'];
        yield 'ddl' => ['alter table `views` add column x int', 'ddl'];
        yield 'truncate' => ['truncate table `views`', 'ddl'];
        yield 'lock tables' => ['lock tables `views` write', 'lock'];
        yield 'analyze' => ['analyze table `views`', 'maintenance'];
        yield 'a procedure' => ['call p()', 'call'];
        yield 'an executable comment holding a write on another table' => ['/*! update `entities` set `name` = ? */', 'executable_comment'];
        yield 'an executable comment with a version number' => ['/*!50000 update `entities` set `name` = ? */', 'executable_comment'];
        yield 'an executable comment that wraps a write on views' => ['/*! update `views` set `views` = 1 where id = 1 */', 'executable_comment'];
        yield 'an executable comment inside a statement on views' => ['update `views` set `views` = 1 /*! , `updated_at` = now() */ where id = 1', 'executable_comment'];
        yield 'an executable comment behind an ordinary one' => ['/* a */ /*!40101 delete from `sessions` */', 'executable_comment'];
        yield 'an executable comment in a read' => ['select /*! 1 */ 1', 'executable_comment'];
        yield 'an executable comment of the other server family' => ['/*M! update `entities` set `name` = ? */', 'executable_comment'];
    }

    #[DataProvider('refused')]
    public function testRefused(string $sql, string $reason): void
    {
        $found = ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, $sql);
        self::assertNotNull($found, 'not refused: ' . $sql);
        self::assertStringStartsWith($reason . ':', $found);
    }

    public function testAWriteOnAnotherConnectionIsRefusedEvenOnViews(): void
    {
        $found = ViewsOnlyWrites::violation('audit', 'mysql', self::DB, 'update `views` set `views` = 1 where id = 1');
        self::assertSame('other_connection: audit', $found);
    }

    public function testAReadOnAnotherConnectionIsNotWhatThisLooksAt(): void
    {
        self::assertNull(ViewsOnlyWrites::violation('audit', 'mysql', self::DB, 'select 1'));
    }

    public function testTheReasonKeepsOnlyTheStartOfALongStatement(): void
    {
        $found = (string) ViewsOnlyWrites::violation('mysql', 'mysql', self::DB, 'update `entities` set `name` = "' . str_repeat('x', 5000) . '"');
        self::assertLessThan(260, mb_strlen($found));
    }
}
