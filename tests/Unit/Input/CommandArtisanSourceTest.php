<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\Input;

use PeakSim\Input\ArtisanFailedException;
use PeakSim\Input\ArtisanOutputNotJsonException;
use PeakSim\Input\ArtisanSource;
use PeakSim\Input\CommandArtisanSource;
use PeakSim\Input\ExportDirSource;
use PeakSim\Input\ProcessResult;
use PeakSim\Input\ProcOpenRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The stub artisan (tests/Support/artisan-stub/artisan) answers from fixtures/artisan-export, so every path of the
 * live-command mode runs on PHP 8.2 too, without a real Laravel.
 */
final class CommandArtisanSourceTest extends TestCase
{
    private const STUB = __DIR__ . '/../../Support/artisan-stub/artisan';
    private const EXPORT_DIR = __DIR__ . '/../../../fixtures/artisan-export';

    private const PHRASE_JA = 'PHP のバージョンが対象の Laravel の対応範囲外の可能性';

    protected function setUp(): void
    {
        putenv('PEAK_STUB_EXPORT_DIR=' . realpath(self::EXPORT_DIR));
        putenv('PEAK_STUB_MODE');
    }

    protected function tearDown(): void
    {
        putenv('PEAK_STUB_EXPORT_DIR');
        putenv('PEAK_STUB_MODE');
    }

    public function testItIsAnArtisanSource(): void
    {
        $source = new CommandArtisanSource(sys_get_temp_dir(), 'php artisan', ScriptedProcessRunner::answering(new ProcessResult(0, '[]', '')));

        self::assertInstanceOf(ArtisanSource::class, $source);
    }

    // ---- same output as the export directory -------------------------------------------------------------

    public function testRoutesEqualThoseOfTheExportDirectory(): void
    {
        self::assertEquals(
            (new ExportDirSource(self::EXPORT_DIR))->routes(),
            $this->stubSource()->routes(),
        );
    }

    public function testEveryModelEqualsThatOfTheExportDirectory(): void
    {
        $export = new ExportDirSource(self::EXPORT_DIR);
        $command = $this->stubSource();
        $seen = 0;

        foreach (glob(self::EXPORT_DIR . '/model-show/*.json') ?: [] as $file) {
            $class = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['class'];
            self::assertEquals($export->modelInfo($class), $command->modelInfo($class), $class);
            self::assertNotNull($command->modelInfo($class), $class);
            $seen++;
        }

        self::assertGreaterThanOrEqual(7, $seen);
    }

    public function testUnknownModelGivesNullLikeTheExportDirectory(): void
    {
        $source = $this->stubSource();

        self::assertNull($source->modelInfo('App\\Models\\NoSuchModel'));
        self::assertNull((new ExportDirSource(self::EXPORT_DIR))->modelInfo('App\\Models\\NoSuchModel'));
    }

    // ---- the command line --------------------------------------------------------------------------------

    public function testRouteListCommandLineAndWorkingDirectory(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, '[]', ''));
        $source = new CommandArtisanSource('/app/root', 'docker compose exec app php artisan', $runner, 33);

        self::assertSame([], $source->routes());

        self::assertCount(1, $runner->calls);
        self::assertSame('docker compose exec app php artisan route:list --json', $runner->calls[0]['commandLine']);
        self::assertSame('/app/root', $runner->calls[0]['cwd']);
        self::assertSame(33, $runner->calls[0]['timeout']);
    }

    public function testTheDefaultArtisanCommandIsPhpArtisan(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, '[]', ''));

        (new CommandArtisanSource('/app/root', runner: $runner))->routes();

        self::assertSame('php artisan route:list --json', $runner->calls[0]['commandLine']);
    }

    public function testArtisanCommandWithPhpOptionsIsUsedAsGiven(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, '[]', ''));

        (new CommandArtisanSource('/app/root', 'php -d display_errors=stderr artisan', $runner))->routes();

        self::assertSame('php -d display_errors=stderr artisan route:list --json', $runner->calls[0]['commandLine']);
    }

    public function testModelClassIsShellEscapedAndLosesItsLeadingBackslash(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(1, '', 'no'));
        $source = new CommandArtisanSource('/app/root', 'php artisan', $runner);

        $source->modelInfo('\\App\\Models\\Order');

        self::assertSame(
            'php artisan model:show ' . escapeshellarg('App\\Models\\Order') . ' --json',
            $runner->calls[0]['commandLine'],
        );
    }

    public function testAClassNameWithShellMetacharactersCannotBreakOutOfTheArgument(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(1, '', 'no'));
        $source = new CommandArtisanSource('/app/root', 'php artisan', $runner);

        $source->modelInfo('App\\Models\\Order; rm x');

        self::assertSame(
            'php artisan model:show ' . escapeshellarg('App\\Models\\Order; rm x') . ' --json',
            $runner->calls[0]['commandLine'],
        );
    }

    public function testModelWithAndWithoutLeadingBackslashIsOneProcessAndOneAnswer(): void
    {
        $runner = ScriptedProcessRunner::recording(new ProcOpenRunner());
        $source = new CommandArtisanSource(sys_get_temp_dir(), $this->stubCommand(), $runner);

        $plain = $source->modelInfo('App\\Models\\Order');
        $leading = $source->modelInfo('\\App\\Models\\Order');

        self::assertNotNull($plain);
        self::assertSame($plain, $leading);
        self::assertCount(1, $runner->calls);
    }

    // ---- laziness and retention --------------------------------------------------------------------------

    public function testNothingIsRunBeforeItIsAsked(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, '[]', ''));

        new CommandArtisanSource('/app/root', 'php artisan', $runner);

        self::assertSame([], $runner->calls);
    }

    public function testRoutesAreFetchedOnceAndKept(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, '[]', ''));
        $source = new CommandArtisanSource('/app/root', 'php artisan', $runner);

        $source->routes();
        $source->routes();

        self::assertCount(1, $runner->calls);
    }

    public function testModelInfoIsFetchedOnlyWhenAskedAndKeptPerClass(): void
    {
        $runner = ScriptedProcessRunner::recording(new ProcOpenRunner());
        $source = new CommandArtisanSource(sys_get_temp_dir(), $this->stubCommand(), $runner);

        $source->routes();
        self::assertCount(1, $runner->calls, 'route:list only; no model:show yet');

        $source->modelInfo('App\\Models\\Order');
        $source->modelInfo('App\\Models\\Order');
        $source->modelInfo('App\\Models\\Customer');
        $source->modelInfo('App\\Models\\Customer');

        self::assertCount(3, $runner->calls);
    }

    public function testAnAbsentModelIsRememberedToo(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(1, '', 'no such model'));
        $source = new CommandArtisanSource('/app/root', 'php artisan', $runner);

        self::assertNull($source->modelInfo('App\\Models\\Ghost'));
        self::assertNull($source->modelInfo('App\\Models\\Ghost'));

        self::assertCount(1, $runner->calls);
    }

    // ---- standard output and standard error are read apart; the exit code comes first -----------------------

    public function testWarningOnStdoutWithExitZeroIsAnOutputNotJsonException(): void
    {
        putenv('PEAK_STUB_MODE=warn_stdout');

        try {
            $this->stubSource()->routes();
            self::fail('an exception was expected');
        } catch (ArtisanOutputNotJsonException $e) {
            self::assertStringContainsString(self::PHRASE_JA, $e->getMessage());
            self::assertStringContainsString('display_errors=stderr', $e->getMessage());
            self::assertStringContainsString('route:list', $e->getMessage());
        }
    }

    public function testNonJsonStdoutWithExitZeroIsAnOutputNotJsonException(): void
    {
        putenv('PEAK_STUB_MODE=nonjson');

        try {
            $this->stubSource()->routes();
            self::fail('an exception was expected');
        } catch (ArtisanOutputNotJsonException $e) {
            self::assertStringContainsString(self::PHRASE_JA, $e->getMessage());
            self::assertStringContainsString('not json', $e->getMessage(), 'the start of stdout is shown');
        }
    }

    public function testTheMessageIsInEnglishToo(): void
    {
        putenv('PEAK_STUB_MODE=nonjson');

        try {
            $this->stubSource()->routes();
            self::fail('an exception was expected');
        } catch (ArtisanOutputNotJsonException $e) {
            self::assertStringContainsString('not valid JSON', $e->getMessage());
            self::assertStringContainsString('outside the range', $e->getMessage());
            self::assertStringContainsString('artisan_command', $e->getMessage());
        }
    }

    public function testExitCodeOneIsAnArtisanFailedExceptionAndStdoutIsNotParsed(): void
    {
        putenv('PEAK_STUB_MODE=exit1');

        try {
            $this->stubSource()->routes();
            self::fail('an exception was expected');
        } catch (ArtisanFailedException $e) {
            self::assertNotInstanceOf(ArtisanOutputNotJsonException::class, $e);
            self::assertStringContainsString('exit code 1', $e->getMessage());
            self::assertStringContainsString('forced failure', $e->getMessage());
        }
    }

    public function testExitCodeIsLookedAtBeforeStdoutEvenWhenStdoutIsValidJson(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(2, '[]', 'boom'));

        try {
            (new CommandArtisanSource('/app/root', 'php artisan', $runner))->routes();
            self::fail('an exception was expected');
        } catch (ArtisanFailedException $e) {
            self::assertNotInstanceOf(ArtisanOutputNotJsonException::class, $e);
            self::assertStringContainsString('exit code 2', $e->getMessage());
        }
    }

    public function testTheBodyLaravelPrintsToStdoutOnFailureIsShownInTheMessage(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(1, "\e[41m ErrorException \e[49m  Undefined variable \$x", ''));

        try {
            (new CommandArtisanSource('/app/root', 'php artisan', $runner))->routes();
            self::fail('an exception was expected');
        } catch (ArtisanFailedException $e) {
            self::assertStringContainsString('Undefined variable', $e->getMessage());
            self::assertStringNotContainsString("\e", $e->getMessage(), 'terminal decoration is stripped');
        }
    }

    public function testBigStderrDoesNotFreezeTheReadAndTheJsonIsRead(): void
    {
        putenv('PEAK_STUB_MODE=big_stderr');
        $source = new CommandArtisanSource(sys_get_temp_dir(), $this->stubCommand(), new ProcOpenRunner(), 30);

        $started = microtime(true);
        $routes = $source->routes();

        self::assertCount(26, $routes);
        self::assertLessThan(25.0, microtime(true) - $started);
    }

    public function testStderrSummaryIsTheFirstTwoKilobytesOnly(): void
    {
        $stderr = str_repeat('A', 2048) . str_repeat('B', 500);
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, 'garbage', $stderr));

        try {
            (new CommandArtisanSource('/app/root', 'php artisan', $runner))->routes();
            self::fail('an exception was expected');
        } catch (ArtisanOutputNotJsonException $e) {
            self::assertStringContainsString(str_repeat('A', 2048), $e->getMessage());
            self::assertStringNotContainsString('B', $e->getMessage());
        }
    }

    public function testStderrSummaryDoesNotCutAMultibyteCharacterInHalf(): void
    {
        $stderr = str_repeat('A', 2047) . 'あ';
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, 'garbage', $stderr));

        try {
            (new CommandArtisanSource('/app/root', 'php artisan', $runner))->routes();
            self::fail('an exception was expected');
        } catch (ArtisanOutputNotJsonException $e) {
            self::assertSame(1, preg_match('//u', $e->getMessage()), 'the message stays valid UTF-8');
        }
    }

    /** @return array<string, array{0: string}> */
    public static function notStrictJsonProvider(): array
    {
        return [
            'empty' => [''],
            'only a newline' => ["\n"],
            'text before the json' => ["Deprecated: x\n[]"],
            'text after the json' => ["[]\nDone."],
            'byte order mark' => ["\xEF\xBB\xBF[]"],
            'single quotes' => ["['a']"],
            'trailing comma' => ['[1,]'],
            'a bare word' => ['null null'],
        ];
    }

    #[DataProvider('notStrictJsonProvider')]
    public function testOnlyStrictJsonIsAccepted(string $stdout): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, $stdout, ''));

        $this->expectException(ArtisanOutputNotJsonException::class);

        (new CommandArtisanSource('/app/root', 'php artisan', $runner))->routes();
    }

    public function testJsonThatIsNotAListOfRoutesIsAnOutputNotJsonException(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, '{"message":"x"}', ''));

        $this->expectException(ArtisanOutputNotJsonException::class);

        (new CommandArtisanSource('/app/root', 'php artisan', $runner))->routes();
    }

    // ---- model:show --------------------------------------------------------------------------------------

    public function testModelShowThatFailsGivesNullAndRemembersWhyWithTheDatabaseHint(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(1, "SQLSTATE[HY000] [2002] Connection refused\n", ''));
        $source = new CommandArtisanSource('/app/root', 'php artisan', $runner);

        self::assertNull($source->modelInfo('App\\Models\\Order'));

        $diagnostics = $source->diagnostics();
        self::assertCount(1, $diagnostics);
        self::assertStringContainsString('App\\Models\\Order', $diagnostics[0]);
        self::assertStringContainsString('exit code 1', $diagnostics[0]);
        self::assertStringContainsString('Connection refused', $diagnostics[0]);
        self::assertStringContainsString('local database', $diagnostics[0]);
        self::assertStringContainsString('到達できるローカル DB', $diagnostics[0]);
        self::assertStringContainsString('--export-dir', $diagnostics[0]);
    }

    public function testStubModelShowFailureMessageCarriesStderr(): void
    {
        $source = $this->stubSource();

        self::assertNull($source->modelInfo('App\\Models\\NoSuchModel'));

        self::assertCount(1, $source->diagnostics());
        self::assertStringContainsString('has no export', $source->diagnostics()[0]);
    }

    public function testNoDiagnosticsWhenEverythingWorks(): void
    {
        $source = $this->stubSource();
        $source->routes();
        $source->modelInfo('App\\Models\\Order');

        self::assertSame([], $source->diagnostics());
    }

    public function testModelShowWithExitZeroButNoJsonIsAnOutputNotJsonException(): void
    {
        putenv('PEAK_STUB_MODE=warn_stdout');

        try {
            $this->stubSource()->modelInfo('App\\Models\\Order');
            self::fail('an exception was expected');
        } catch (ArtisanOutputNotJsonException $e) {
            self::assertStringContainsString(self::PHRASE_JA, $e->getMessage());
            self::assertStringContainsString('model:show', $e->getMessage());
        }
    }

    public function testModelShowOfTheWrongShapeIsAnOutputNotJsonException(): void
    {
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, '[1,2]', ''));

        $this->expectException(ArtisanOutputNotJsonException::class);

        (new CommandArtisanSource('/app/root', 'php artisan', $runner))->modelInfo('App\\Models\\Order');
    }

    public function testModelShowOfAnotherClassThanAskedIsNotTaken(): void
    {
        $order = (string) file_get_contents(self::EXPORT_DIR . '/model-show/Order.json');
        $runner = ScriptedProcessRunner::answering(new ProcessResult(0, $order, ''));

        self::assertNull((new CommandArtisanSource('/app/root', 'php artisan', $runner))->modelInfo('App\\Models\\Customer'));
    }

    public function testARunnerFailureIsPassedOn(): void
    {
        $runner = ScriptedProcessRunner::with(static function (): ProcessResult {
            throw new ArtisanFailedException('cannot start');
        });

        $this->expectException(ArtisanFailedException::class);

        (new CommandArtisanSource('/app/root', 'php artisan', $runner))->routes();
    }

    // ---- helpers -----------------------------------------------------------------------------------------

    private function stubCommand(): string
    {
        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg((string) realpath(self::STUB));
    }

    private function stubSource(): CommandArtisanSource
    {
        return new CommandArtisanSource(sys_get_temp_dir(), $this->stubCommand(), new ProcOpenRunner(), 30);
    }
}
