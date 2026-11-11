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

    public function test_the_gate_is_let_through_after_a_403_unless_the_run_says_off(): void
    {
        $this->assertTrue(Options::parse([])->gateBypass);
        $this->assertTrue(Options::parse(['--gate-bypass=on'])->gateBypass);
        $this->assertFalse(Options::parse(['--gate-bypass=off'])->gateBypass);
    }

    public function test_the_gate_switch_takes_on_or_off_only(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Options::parse(['--gate-bypass=maybe']);
    }

    public function test_the_warm_request_is_made_unless_the_run_says_off(): void
    {
        $this->assertTrue(Options::parse([])->warm);
        $this->assertTrue(Options::parse(['--warm=on'])->warm);
        $this->assertFalse(Options::parse(['--warm=off'])->warm);
    }

    public function test_the_warm_switch_takes_on_or_off_only(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Options::parse(['--warm=sometimes']);
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

    public function test_only_with_an_equals_prefix_matches_the_whole_uri(): void
    {
        $o = Options::parse(['--only==/api/v1/transactions/{transactionGroup},=/json/box/available']);

        $this->assertTrue($o->wants('/api/v1/transactions/{transactionGroup}'));
        $this->assertTrue($o->wants('/json/box/available'));
        $this->assertFalse($o->wants('/api/v1/transactions/{transactionGroup}/attachments'));
        $this->assertFalse($o->wants('/json/box/available/extra'));
    }

    public function test_only_with_a_method_and_a_uri_names_one_route(): void
    {
        $o = Options::parse(['--only=GET /books/{slug},PUT /books/{slug}']);

        $this->assertTrue($o->wants('/books/{slug}', 'GET'));
        $this->assertTrue($o->wants('/books/{slug}', 'PUT'));
        $this->assertFalse($o->wants('/books/{slug}', 'DELETE'));
        $this->assertFalse($o->wants('/books/{slug}/copy', 'GET'));
        // without a method there is nothing to compare: such a needle names no route
        $this->assertFalse($o->wants('/books/{slug}'));
        // the older forms are unchanged beside it
        $mixed = Options::parse(['--only=GET /a/{id},/b/']);
        $this->assertTrue($mixed->wants('/b/x', 'POST'));
        $this->assertFalse($mixed->wants('/a/{id}', 'POST'));
    }

    public function test_no_statement_records_are_asked_for_unless_the_run_names_a_directory(): void
    {
        $this->assertNull(Options::parse([])->tapDir);
        $this->assertSame('/out/capture-taps', Options::parse(['--tap-dir=/out/capture-taps'])->tapDir);
    }

    #[DataProvider('badTapDirs')]
    public function test_the_tap_directory_must_be_an_absolute_path_without_dots(string $arg): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Options::parse([$arg]);
    }

    /** @return iterable<string, array{string}> */
    public static function badTapDirs(): iterable
    {
        yield 'relative' => ['--tap-dir=out/taps'];
        yield 'with dots' => ['--tap-dir=/out/../etc'];
        yield 'empty' => ['--tap-dir='];
    }

    public function test_no_shared_n_tables_unless_the_run_names_them(): void
    {
        $this->assertSame([], Options::parse([])->nTables);
        $this->assertSame(['entities', 'images', 'references'], Options::parse(['--n-tables=entities,images,references'])->nTables);
        $this->assertSame(['entities'], Options::parse(['--n-tables=entities', '--data=generator'])->nTables);
    }

    #[DataProvider('badNTables')]
    public function test_the_n_tables_are_plain_table_names(string ...$args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Options::parse($args);
    }

    /** @return iterable<string, list<string>> */
    public static function badNTables(): iterable
    {
        yield 'empty' => ['--n-tables='];
        yield 'empty name between commas' => ['--n-tables=a,,b'];
        yield 'trailing comma' => ['--n-tables=a,'];
        yield 'upper case' => ['--n-tables=Entities'];
        yield 'schema dot' => ['--n-tables=db.entities'];
        yield 'quote' => ['--n-tables=en`tities'];
        yield 'with the seeder, which has its own shape' => ['--data=seeder', '--n-tables=entities'];
    }

    public function test_the_statistics_are_not_analysed_after_a_build_unless_the_run_asks_for_it(): void
    {
        $this->assertFalse(Options::parse([])->analyzeAfterBuild);
        $this->assertFalse(Options::parse(['--analyze-after-build=off'])->analyzeAfterBuild);
        $this->assertTrue(Options::parse(['--analyze-after-build=on'])->analyzeAfterBuild);
        $this->expectException(\InvalidArgumentException::class);
        Options::parse(['--analyze-after-build=maybe']);
    }
}
