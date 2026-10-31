<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Cli;

use PeakSim\Name;
use PeakSim\Tests\Support\MiniAppWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exit code 2: the command line is wrong. Nothing is analysed, nothing goes to standard output, and standard
 * error says what was wrong and shows the usage (design 1.13).
 */
final class UsageTest extends CliTestCase
{
    /** @return array<string, array{0: list<string>, 1: string}> arguments (the app root is filled in for "@app") and a word the message must contain */
    public static function badCommandLines(): array
    {
        return [
            'no arguments at all' => [[], 'usage'],
            'an unknown command' => [['explain'], 'explain'],
            'analyze without an app root' => [['analyze'], 'app-root'],
            'two app roots' => [['analyze', '@app', '@app'], 'app-root'],
            'an unknown option' => [['analyze', '@app', '--verbose'], '--verbose'],
            'both ways in at once' => [['analyze', '@app', '--artisan-command=php artisan', '--export-dir=@export'], 'exclusive'],
            'a format that does not exist' => [['analyze', '@app', '--format=xml'], '--format'],
            'a max depth that is not a number' => [['analyze', '@app', '--max-depth=deep'], '--max-depth'],
            'a max depth below 1' => [['analyze', '@app', '--max-depth=0'], '--max-depth'],
            'an empty route' => [['analyze', '@app', '--route='], '--route'],
            'an empty export dir' => [['analyze', '@app', '--export-dir='], '--export-dir'],
            'an empty artisan command' => [['analyze', '@app', '--artisan-command='], '--artisan-command'],
            'an app root that is not a directory' => [['analyze', '/no/such/dir/for/peak/sim'], 'app-root'],
        ];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('badCommandLines')]
    public function testABadCommandLineExitsWithTwoAndSaysWhy(array $args, string $mustMention): void
    {
        $args = array_map(
            static fn (string $a): string => str_replace(['@app', '@export'], [MiniAppWorkspace::fixtureApp(), MiniAppWorkspace::fixtureExport()], $a),
            $args,
        );

        $result = $this->peakSim($args);

        $this->assertSame(2, $result->exitCode, "stderr:\n{$result->stderr}");
        $this->assertSame('', $result->stdout, 'a usage error prints nothing to standard output');
        $this->assertStringContainsStringIgnoringCase($mustMention, $result->stderr);
        $this->assertStringContainsString(Name::TOOL_NAME, $result->stderr, 'the usage carries the tool name');
        $this->assertStringContainsString('analyze', $result->stderr, 'the usage names the command');
    }

    public function testAskingForHelpPrintsTheUsageToStandardOutputAndExitsWithZero(): void
    {
        foreach (['--help', '-h'] as $flag) {
            $result = $this->peakSim([$flag]);

            $this->assertSame(0, $result->exitCode, $flag);
            $this->assertSame('', $result->stderr, $flag);
            $this->assertStringContainsString(Name::TOOL_NAME . ' analyze', $result->stdout, $flag);
            foreach (['--artisan-command', '--export-dir', '--format', '--route', '--max-depth', '--stats'] as $option) {
                $this->assertStringContainsString($option, $result->stdout, "{$flag}: usage lists {$option}");
            }
        }
    }

    public function testAnUnknownRouteIsAUsageError(): void
    {
        $result = $this->analyze($this->exportOption(), '--route=/no/such/route');

        $this->assertSame(2, $result->exitCode, $result->stderr);
        $this->assertSame('', $result->stdout);
        $this->assertStringContainsString('/no/such/route', $result->stderr);
    }

    public function testTheToolNameComesFromTheOneConstant(): void
    {
        $source = (string) file_get_contents(MiniAppWorkspace::projectRoot() . '/src/Cli/Application.php');

        $this->assertStringContainsString('Name::TOOL_NAME', $source);
    }
}
