<?php

declare(strict_types=1);

namespace PeakSim\Tests\Acceptance\Cli;

use PeakSim\Cli\Application;
use PeakSim\Input\ProcessResult;
use PeakSim\Input\ProcOpenRunner;
use PeakSim\Tests\Support\MiniAppWorkspace;

/**
 * D41: when PHP runs out of memory the tool must not end silently. Every case starts the real binary as a child
 * process with a small `memory_limit` (php -d …), so the shutdown path, the exit code and the two streams are the
 * ones a user sees. 8M is below what the fixture app needs and above what the tool needs to start.
 */
final class MemoryLimitTest extends CliTestCase
{
    private const SMALL = '8M';

    public function testRunningOutOfMemoryExplainsTheCauseAndHowToRerunAndExitsWithFive(): void
    {
        $result = $this->analyzeWithIni('memory_limit=' . self::SMALL);

        $this->assertSame(5, $result->exitCode, $result->stderr);
        $this->assertStringContainsString('out of memory', $result->stderr);
        $this->assertStringContainsString('--memory-limit=2G', $result->stderr);
        $this->assertStringContainsString(self::SMALL, $result->stderr, 'the limit that was in force');
        $this->assertSame('', $result->stdout, 'the report is written whole or not at all');
    }

    public function testTheExitCodeDoesNotOverlapTheOnesAlreadyInUse(): void
    {
        $this->assertNotContains(5, [Application::EXIT_OK, Application::EXIT_USAGE, Application::EXIT_INPUT, Application::EXIT_INTERNAL]);
        $this->assertSame(5, Application::EXIT_MEMORY);
    }

    public function testMemoryLimitGivenOnTheCommandLineLetsTheSameRunComplete(): void
    {
        $result = $this->analyzeWithIni('memory_limit=' . self::SMALL, '--memory-limit=256M', '--format=json');

        $this->assertSame(0, $result->exitCode, $result->stderr);
        $this->assertSame('', $result->stderr);
        $this->assertSame('0.2', $this->decodeJson($result->stdout)['schema_version']);
    }

    public function testUnlimitedMayBeAskedForByTheUserButIsNeverChosenByTheTool(): void
    {
        $asked = $this->analyzeWithIni('memory_limit=' . self::SMALL, '--memory-limit=-1');
        $this->assertSame(0, $asked->exitCode, $asked->stderr);

        // Not asked: the php.ini value (here the child's -d) stays in force and the run still runs out.
        $notAsked = $this->analyzeWithIni('memory_limit=' . self::SMALL);
        $this->assertSame(5, $notAsked->exitCode, $notAsked->stderr);
    }

    public function testANormalRunPrintsNoAdviceAndTheOutputDoesNotChange(): void
    {
        $plain = $this->analyzeWithIni('memory_limit=256M', '--format=json');
        $limited = $this->analyzeWithIni('memory_limit=256M', '--memory-limit=512M', '--format=json');

        $this->assertSame(0, $plain->exitCode, $plain->stderr);
        $this->assertSame('', $plain->stderr);
        $this->assertSame(0, $limited->exitCode, $limited->stderr);
        $this->assertSame('', $limited->stderr);
        $this->assertSame($plain->stdout, $limited->stdout, 'the option changes the limit, never the report');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLimits(): iterable
    {
        yield 'empty' => ['--memory-limit='];
        yield 'no value' => ['--memory-limit'];
        yield 'not a number' => ['--memory-limit=lots'];
        yield 'unknown unit' => ['--memory-limit=12X'];
        yield 'fraction' => ['--memory-limit=1.5G'];
        yield 'negative other than -1' => ['--memory-limit=-2'];
        yield 'zero' => ['--memory-limit=0'];
        yield 'unit only' => ['--memory-limit=M'];
        yield 'space inside' => ['--memory-limit=1 G'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidLimits')]
    public function testAnInvalidLimitIsTreatedLikeAnyOtherBadOption(string $option): void
    {
        $result = $this->analyze($this->exportOption(), $option);

        $this->assertSame(Application::EXIT_USAGE, $result->exitCode, $result->stderr);
        $this->assertSame('', $result->stdout);
        $this->assertStringContainsString('--memory-limit', $result->stderr);
    }

    /** @return iterable<string, array{string}> */
    public static function validLimits(): iterable
    {
        yield 'bytes' => ['--memory-limit=268435456'];
        yield 'kilobytes' => ['--memory-limit=262144K'];
        yield 'megabytes' => ['--memory-limit=256M'];
        yield 'gigabytes' => ['--memory-limit=1G'];
        yield 'lower case unit' => ['--memory-limit=1g'];
        yield 'unlimited' => ['--memory-limit=-1'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validLimits')]
    public function testTheUsualWaysToWriteALimitAreAccepted(string $option): void
    {
        $result = $this->analyze($this->exportOption(), $option, '--format=json');

        $this->assertSame(0, $result->exitCode, $result->stderr);
        $this->assertSame('0.2', $this->decodeJson($result->stdout)['schema_version']);
    }

    public function testTheOptionIsListedInTheHelp(): void
    {
        $result = $this->peakSim(['--help']);

        $this->assertSame(0, $result->exitCode);
        $this->assertStringContainsString('--memory-limit=<size>', $result->stdout);
        $this->assertStringContainsString('5 out of memory', $result->stdout);
    }

    public function testWithoutTheOptionTheLimitIsNotTouched(): void
    {
        $calls = [];
        $app = new Application(null, static function (string $value) use (&$calls): bool {
            $calls[] = $value;

            return true;
        });
        $stderr = fopen('php://memory', 'w+');
        $stdout = fopen('php://memory', 'w+');
        $this->assertIsResource($stderr);
        $this->assertIsResource($stdout);

        $app->run(['peak-sim', 'analyze', MiniAppWorkspace::fixtureApp(), $this->exportOption()], $stdout, $stderr);
        $this->assertSame([], $calls, 'no option, no ini_set: php.ini decides');

        $app->run(['peak-sim', 'analyze', MiniAppWorkspace::fixtureApp(), $this->exportOption(), '--memory-limit=1G'], $stdout, $stderr);
        $this->assertSame(['1G'], $calls);
    }

    public function testALimitBelowWhatIsAlreadyInUseIsRefusedAsABadOption(): void
    {
        $app = new Application(null, static fn (string $value): bool => false);
        $stderr = fopen('php://memory', 'w+');
        $stdout = fopen('php://memory', 'w+');
        $this->assertIsResource($stderr);
        $this->assertIsResource($stdout);

        $code = $app->run(['peak-sim', 'analyze', MiniAppWorkspace::fixtureApp(), $this->exportOption(), '--memory-limit=1K'], $stdout, $stderr);

        rewind($stderr);
        $this->assertSame(Application::EXIT_USAGE, $code);
        $this->assertStringContainsString('--memory-limit', (string) stream_get_contents($stderr));
    }

    public function testAnotherFatalErrorGetsNoAdviceAndKeepsPhpsOwnExitCode(): void
    {
        $result = $this->runGuardedScript('undefined_function_for_the_test();');

        $this->assertSame(255, $result->exitCode, $result->stderr);
        $this->assertStringNotContainsString('--memory-limit', $result->stderr);
    }

    public function testTheAdviceSurvivesAHeapThatIsFullToTheLastByte(): void
    {
        // Grows an array until PHP gives up: the shutdown function then has next to nothing to work with.
        $result = $this->runGuardedScript('$a = []; while (true) { $a[] = str_repeat("x", 1024); }', '4M');

        $this->assertSame(5, $result->exitCode, $result->stderr);
        $this->assertStringContainsString('--memory-limit=1G', $result->stderr);
    }

    /** @param string ...$options after `analyze <fixture app> --export-dir=…` */
    private function analyzeWithIni(string $ini, string ...$options): ProcessResult
    {
        $binary = MiniAppWorkspace::projectRoot() . '/bin/peak-sim';
        $command = escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg($ini) . ' ' . escapeshellarg($binary)
            . ' analyze ' . escapeshellarg(MiniAppWorkspace::fixtureApp()) . ' ' . escapeshellarg($this->exportOption())
            . ($options === [] ? '' : ' ' . implode(' ', array_map('escapeshellarg', $options)));

        return (new ProcOpenRunner())->run($command, MiniAppWorkspace::projectRoot(), 120);
    }

    /** Runs a snippet after MemoryGuard::register(), the way bin/peak-sim does. */
    private function runGuardedScript(string $body, string $limit = '128M'): ProcessResult
    {
        $dir = MiniAppWorkspace::make('memory-guard');
        try {
            $script = $dir . '/guarded.php';
            file_put_contents($script, "<?php\nrequire " . var_export(MiniAppWorkspace::projectRoot() . '/vendor/autoload.php', true)
                . ";\nini_set('display_errors', 'stderr');\n\\PeakSim\\Cli\\MemoryGuard::register();\n" . $body . "\n");
            $command = escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('memory_limit=' . $limit) . ' ' . escapeshellarg($script);

            return (new ProcOpenRunner())->run($command, $dir, 60);
        } finally {
            MiniAppWorkspace::remove($dir);
        }
    }
}
