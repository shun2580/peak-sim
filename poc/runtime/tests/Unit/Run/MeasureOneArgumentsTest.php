<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Run;

use PeakSim\Runtime\Run\Attempt;
use PeakSim\Runtime\Run\MeasureOne;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MeasureOneArgumentsTest extends TestCase
{
    public function testDefaults(): void
    {
        $a = MeasureOne::parseArguments(['--scale=100', '--uri=/ok/eager']);
        self::assertSame(100, $a['scale']);
        self::assertSame('GET', $a['method']);
        self::assertSame('ok/eager', $a['route']);
        self::assertSame('N', $a['axis']);
        self::assertSame([], $a['headers']);
        self::assertNull($a['body']);
        self::assertTrue($a['json']);
        self::assertFalse($a['csrf']);
        self::assertNull($a['actAs']);
        self::assertFalse($a['gateBypass']);
        self::assertSame([Attempt::WARMUP, Attempt::COLD, Attempt::WARM], $a['phases']);
    }

    public function testEverythingGiven(): void
    {
        $a = MeasureOne::parseArguments([
            '--scale=1000', '--uri=/orders/1', '--method=post', '--route=orders/{order}', '--axis=M',
            '--header=X-One: 1', '--header=X-Two:2', '--body-json={"a":1}', '--form', '--csrf',
            '--act-as=App\\Models\\User:7:web', '--gate-bypass', '--phases=cold,warm',
        ]);
        self::assertSame('POST', $a['method']);
        self::assertSame('orders/{order}', $a['route']);
        self::assertSame('M', $a['axis']);
        self::assertSame(['X-One' => '1', 'X-Two' => '2'], $a['headers']);
        self::assertSame(['a' => 1], $a['body']);
        self::assertFalse($a['json']);
        self::assertTrue($a['csrf']);
        self::assertSame('App\\Models\\User:7:web', $a['actAs']);
        self::assertTrue($a['gateBypass']);
        self::assertSame([Attempt::COLD, Attempt::WARM], $a['phases']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function bad(): iterable
    {
        yield 'no scale' => [['--uri=/x']];
        yield 'scale is not a number' => [['--scale=abc', '--uri=/x']];
        yield 'scale is negative' => [['--scale=-1', '--uri=/x']];
        yield 'no uri' => [['--scale=100']];
        yield 'uri without a slash' => [['--scale=100', '--uri=x']];
        yield 'uri with a scheme' => [['--scale=100', '--uri=http://example.com/x']];
        yield 'bad method' => [['--scale=100', '--uri=/x', '--method=TRACE']];
        yield 'bad header' => [['--scale=100', '--uri=/x', '--header=oops']];
        yield 'body is not an object' => [['--scale=100', '--uri=/x', '--body-json=3']];
        yield 'body is not json' => [['--scale=100', '--uri=/x', '--body-json={']];
        yield 'unknown option' => [['--scale=100', '--uri=/x', '--database=app']];
        yield 'positional argument' => [['--scale=100', '--uri=/x', 'extra']];
        yield 'bad phase' => [['--scale=100', '--uri=/x', '--phases=hot']];
        yield 'bad act-as' => [['--scale=100', '--uri=/x', '--act-as=nonsense']];
    }

    /** @param list<string> $argv */
    #[DataProvider('bad')]
    public function testRefusesBadInput(array $argv): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MeasureOne::parseArguments($argv);
    }

    public function testThereIsNoWayToPassADatabaseName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MeasureOne::parseArguments(['--scale=100', '--uri=/x', '--db=app']);
    }
}
