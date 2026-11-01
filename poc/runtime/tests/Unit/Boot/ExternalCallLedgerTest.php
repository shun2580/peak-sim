<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Boot;

use PeakSim\Runtime\Boot\ExternalCallLedger;
use PHPUnit\Framework\TestCase;

final class ExternalCallLedgerTest extends TestCase
{
    public function testKeepsOnlyTheHostNeverThePathOrQuery(): void
    {
        $ledger = new ExternalCallLedger();
        $ledger->add('https://api.example.test:8443/v1/users?token=secret#frag');
        self::assertSame(['api.example.test'], $ledger->hosts());
    }

    public function testNoDuplicatesAndOrderOfFirstSight(): void
    {
        $ledger = new ExternalCallLedger();
        $ledger->add('http://b.example.test/x');
        $ledger->add('http://a.example.test/x');
        $ledger->add('http://b.example.test/y');
        self::assertSame(['b.example.test', 'a.example.test'], $ledger->hosts());
    }

    public function testUnparsableUrlIsRecordedAsUnknown(): void
    {
        $ledger = new ExternalCallLedger();
        $ledger->add('not a url');
        self::assertSame(['(unknown host)'], $ledger->hosts());
    }

    public function testClear(): void
    {
        $ledger = new ExternalCallLedger();
        $ledger->add('http://a.example.test/');
        $ledger->clear();
        self::assertSame([], $ledger->hosts());
    }
}
