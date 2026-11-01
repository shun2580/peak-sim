<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function test_defaults(): void
    {
        $o = Options::parse([]);

        $this->assertSame('generator', $o->data);
        $this->assertSame([], $o->only);
        $this->assertNull($o->expected);
        $this->assertSame(Options::DEFAULT_BASE, $o->base);
    }

    public function test_everything_given(): void
    {
        $o = Options::parse(['--data=seeder', '--only=/ok/eager,/ng/', '--expected=/out/expected.json']);

        $this->assertSame('seeder', $o->data);
        $this->assertSame(['/ok/eager', '/ng/'], $o->only);
        $this->assertSame('/out/expected.json', $o->expected);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function bad(): iterable
    {
        yield 'unknown option' => [['--bogus']];
        yield 'not an option' => [['x']];
        yield 'data kind' => [['--data=dump']];
        yield 'empty only' => [['--only=']];
        yield 'relative expected' => [['--expected=x.json']];
        yield 'dotdot expected' => [['--expected=/out/../x.json']];
        yield 'option without value' => [['--data']];
    }

    /** @param list<string> $argv */
    #[DataProvider('bad')]
    public function test_bad_usage_is_refused(array $argv): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Options::parse($argv);
    }

    public function test_only_matches_by_substring_of_the_route(): void
    {
        $o = Options::parse(['--only=/ok/,/unknown']);

        $this->assertTrue($o->wants('/ok/eager'));
        $this->assertTrue($o->wants('/unknown/dynamic'));
        $this->assertFalse($o->wants('/ng/service'));
        $this->assertTrue(Options::parse([])->wants('/anything'));
    }
}
