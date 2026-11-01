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
        $this->assertNull($o->profile);
    }

    public function test_a_profile_is_named_not_pathed(): void
    {
        $this->assertSame('fixtures', Options::parse(['--profile=fixtures'])->profile);
        $this->assertSame('my-app_2', Options::parse(['--profile=my-app_2'])->profile);
    }

    public function test_everything_given(): void
    {
        $o = Options::parse(['--data=seeder', '--only=/ok/eager,/ng/', '--expected=/out/expected.json']);

        $this->assertSame('seeder', $o->data);
        $this->assertSame(['/ok/eager', '/ng/'], $o->only);
        $this->assertSame('/out/expected.json', $o->expected);
    }

    public function test_the_inspection_child_gets_the_scale_the_routes_and_the_profile_of_the_run(): void
    {
        $o = Options::parse(['--data=generator', '--only=/ok/eager,/ng/', '--profile=koel', '--base=7']);

        $this->assertSame(['--scale=5', '--only=/ok/eager,/ng/', '--profile=koel'], $o->inspectionArguments(5));
    }

    public function test_the_inspection_child_of_a_run_without_a_profile_is_asked_as_before(): void
    {
        $this->assertSame(['--scale=0'], Options::parse([])->inspectionArguments(0));
        $this->assertSame(['--scale=10', '--only=/a'], Options::parse(['--only=/a'])->inspectionArguments(10));
    }

    public function test_what_the_inspection_child_is_given_is_what_it_parses_back(): void
    {
        $o = Options::parse(['--only=/ok/eager,/ng/', '--profile=my-app_2']);
        $rest = array_values(array_filter($o->inspectionArguments(3), static fn (string $a): bool => !str_starts_with($a, '--scale=')));

        $back = Options::parse($rest);

        $this->assertSame(['/ok/eager', '/ng/'], $back->only);
        $this->assertSame('my-app_2', $back->profile);
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
        yield 'empty profile' => [['--profile=']];
        yield 'profile with a path' => [['--profile=../x']];
        yield 'profile with a slash' => [['--profile=a/b']];
        yield 'profile with a dot' => [['--profile=a.php']];
        yield 'profile in capitals' => [['--profile=Fixtures']];
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
