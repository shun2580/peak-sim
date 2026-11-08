<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Explain\RelatedTables;
use PHPUnit\Framework\TestCase;

final class RelatedTablesTest extends TestCase
{
    public function testItReadsTablesFromSelectsJoinsUpdatesInsertsAndDeletes(): void
    {
        $this->assertSame(['orders', 'customers'], RelatedTables::of('select * from `orders` inner join `customers` on `customers`.`id` = `orders`.`customer_id` where `orders`.`id` = ?'));
        $this->assertSame(['deletions'], RelatedTables::of('update `deletions` set `a` = ? where `id` = ?'));
        $this->assertSame(['activities'], RelatedTables::of('insert into `activities` (`type`) values (?)'));
        $this->assertSame(['references'], RelatedTables::of('delete from `references` where `from_type` = ? and `from_id` in (?, ?)'));
    }

    public function testItReadsAFromList(): void
    {
        $names = RelatedTables::of('select * from a, b as bb, `c` where a.id = b.id');

        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $names);
    }

    public function testATextAndACommentAreNotTables(): void
    {
        $this->assertSame(['users'], RelatedTables::of("select * from users where note = 'from ghost' /* join phantom */"));
    }

    public function testAStatementItCannotReadGivesNothing(): void
    {
        $this->assertSame([], RelatedTables::of("select 'abc"));
    }
}
