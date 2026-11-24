<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Variance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The host-side repeat runner and its watch answer a wrong usage before they touch Docker (exit 2 and the usage text),
 * pass the app key by name, and contain no kill of any kind.
 */
final class VarianceScriptsUsageTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/peak-variance-usage-' . bin2hex(random_bytes(4));
        mkdir(self::$dir . '/app/vendor', 0775, true);
        touch(self::$dir . '/app/vendor/autoload.php');
        file_put_contents(self::$dir . '/tasks.json', '[]');
        mkdir(self::$dir . '/out/taken', 0775, true);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$dir . '/app/vendor/autoload.php');
        @rmdir(self::$dir . '/app/vendor');
        @rmdir(self::$dir . '/app');
        @unlink(self::$dir . '/tasks.json');
        @rmdir(self::$dir . '/out/taken');
        @rmdir(self::$dir . '/out');
        @rmdir(self::$dir);
    }

    private static function script(string $name): string
    {
        return dirname(__DIR__, 3) . '/bin/' . $name;
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function invoke(string $script, array $args): array
    {
        $process = proc_open(['bash', self::script($script), ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(),
        ]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function invoke_at(string $path, array $args): array
    {
        $process = proc_open(['bash', $path, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(),
        ]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /** @return list<string> the arguments that are fine, so a test can spoil one */
    private static function good(): array
    {
        return ['--app=' . self::$dir . '/app', '--tasks=' . self::$dir . '/tasks.json', '--scale=1000', '--out=' . self::$dir . '/out'];
    }

    /** @return iterable<string, array{list<string>}> */
    public static function bad(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'unknown option' => [['--bogus']];
        yield 'relative app' => [['--app=app', '--tasks=/t', '--scale=1', '--out=/o']];
        yield 'relative tasks' => [['--app=/a', '--tasks=t', '--scale=1', '--out=/o']];
        yield 'relative out' => [['--app=/a', '--tasks=/t', '--scale=1', '--out=o']];
        yield 'no scale' => [['--app=/a', '--tasks=/t', '--out=/o']];
        yield 'app that is not there' => [['--app=/no/such/app', '--tasks=/t', '--scale=1', '--out=/o']];
    }

    /** @param list<string> $args */
    #[DataProvider('bad')]
    public function test_a_wrong_usage_exits_with_2_and_says_how_to_use_it(array $args): void
    {
        [$code, , $err] = self::invoke('variance-run', $args);

        $this->assertSame(2, $code, $err);
        $this->assertStringContainsString('usage: bin/variance-run', $err);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function badOptions(): iterable
    {
        yield 'scale of zero' => [['--scale=0']];
        yield 'scale not a number' => [['--scale=many']];
        yield 'no databases' => [['--dbs=0']];
        yield 'no laps' => [['--laps=0']];
        yield 'label with a slash' => [['--label=a/b']];
        yield 'routes without a method' => [['--routes=/a']];
        yield 'routes with an empty entry' => [['--routes=GET /a,,GET /b']];
        yield 'tap maybe' => [['--tap=maybe']];
        yield 'analyze sometimes' => [['--analyze=sometimes']];
        yield 'stats auto recalc sometimes' => [['--stats-auto-recalc=sometimes']];
        yield 'stats tables with a space' => [['--stats-tables=a b']];
        yield 'stats tables with an empty entry' => [['--stats-tables=a,,b']];
        yield 'stats off together with analyze' => [['--stats-auto-recalc=off', '--analyze=before']];
        yield 'timeout of zero' => [['--request-timeout=0']];
        yield 'timeout over ten minutes' => [['--request-timeout=601']];
        yield 'watch interval of zero' => [['--watch-interval=0']];
        yield 'profile with a path' => [['--profile=../x']];
        yield 'data kind unknown' => [['--data=dump']];
        yield 'n tables with a capital' => [['--n-tables=Entities']];
        yield 'n tables with an empty name' => [['--n-tables=a,,b']];
        yield 'n tables empty' => [['--n-tables=']];
        yield 'n tables with a schema dot' => [['--n-tables=db.entities']];
        yield 'n tables with the seeder' => [['--data=seeder', '--n-tables=entities']];
        yield 'parallel without a php image' => [['--parallel']];
        yield 'notifier that is not a file' => [['--notify=/no/such/notifier']];
        yield 'a record that already exists' => [['--label=taken', '--out=OUT']];
    }

    /** @param list<string> $extra */
    #[DataProvider('badOptions')]
    public function test_a_wrong_option_exits_with_2_before_anything_is_started(array $extra): void
    {
        $args = array_map(static fn (string $a): string => $a === '--out=OUT' ? '--out=' . self::$dir . '/out' : $a, $extra);
        // an option given later wins over the good one given first
        [$code, , $err] = self::invoke('variance-run', [...self::good(), ...$args]);

        $this->assertSame(2, $code, $err);
        $this->assertStringContainsString('bin/variance-run', $err);
    }

    public function test_shared_n_tables_pass_the_checks_of_the_script_and_stop_only_at_the_record_that_exists(): void
    {
        [$code, , $err] = self::invoke('variance-run', [...self::good(), '--label=taken', '--source-commit=abc1234', '--n-tables=entities,images,references']);

        $this->assertSame(2, $code, $err);
        $this->assertStringContainsString('already exists', $err);
    }

    public function test_help_names_the_n_tables_option(): void
    {
        [$code, $out] = self::invoke('variance-run', ['--help']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('--n-tables', $out);
    }

    /**
     * Makes a throwaway git checkout holding a copy of the runner under bin/, with one commit.
     *
     * @return string the checkout's directory
     */
    private static function checkoutWithRunner(): string
    {
        $root = self::$dir . '/checkout-' . bin2hex(random_bytes(4));
        mkdir($root . '/bin', 0775, true);
        copy(self::script('variance-run'), $root . '/bin/variance-run');
        foreach ([['init', '-q'], ['add', 'bin/variance-run'], ['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '-m', 'init']] as $git) {
            $process = proc_open(['git', '-C', $root, ...$git], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), 'git ' . implode(' ', $git));
        }

        return $root;
    }

    private static function removeCheckout(string $root): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }

    public function test_a_tree_with_uncommitted_changes_is_refused_when_no_source_commit_is_given(): void
    {
        $root = self::checkoutWithRunner();
        try {
            file_put_contents($root . '/bin/variance-run', "\n# uncommitted\n", FILE_APPEND);

            [$code, , $err] = self::invoke_at($root . '/bin/variance-run', [...self::good(), '--label=taken']);

            $this->assertSame(2, $code, $err);
            $this->assertStringContainsString('uncommitted changes', $err);
            $this->assertStringNotContainsString('already exists', $err, 'it must stop before it looks at the record');
        } finally {
            self::removeCheckout($root);
        }
    }

    public function test_an_untracked_file_in_the_tree_also_counts_as_uncommitted(): void
    {
        $root = self::checkoutWithRunner();
        try {
            file_put_contents($root . '/bin/new-file', 'x');

            [$code, , $err] = self::invoke_at($root . '/bin/variance-run', [...self::good(), '--label=taken']);

            $this->assertSame(2, $code, $err);
            $this->assertStringContainsString('uncommitted changes', $err);
        } finally {
            self::removeCheckout($root);
        }
    }

    public function test_a_given_source_commit_is_not_held_up_by_a_dirty_tree(): void
    {
        $root = self::checkoutWithRunner();
        try {
            file_put_contents($root . '/bin/variance-run', "\n# uncommitted\n", FILE_APPEND);

            // it gets past the check and meets the record that already exists, which is the next thing it looks at
            [$code, , $err] = self::invoke_at($root . '/bin/variance-run', [...self::good(), '--label=taken', '--source-commit=abc1234']);

            $this->assertSame(2, $code, $err);
            $this->assertStringContainsString('already exists', $err);
            $this->assertStringNotContainsString('uncommitted changes', $err);
        } finally {
            self::removeCheckout($root);
        }
    }

    public function test_a_clean_tree_is_not_refused_for_being_dirty(): void
    {
        $root = self::checkoutWithRunner();
        try {
            [$code, , $err] = self::invoke_at($root . '/bin/variance-run', [...self::good(), '--label=taken']);

            $this->assertSame(2, $code, $err);
            $this->assertStringContainsString('already exists', $err);
            $this->assertStringNotContainsString('uncommitted changes', $err);
        } finally {
            self::removeCheckout($root);
        }
    }

    public function test_the_help_exits_0_and_names_the_options(): void
    {
        [$code, $out] = self::invoke('variance-run', ['--help']);

        $this->assertSame(0, $code);
        foreach (['--tasks', '--dbs', '--laps', '--parallel', '--tap', '--analyze', '--stats-auto-recalc', '--stats-tables', '--notify', '--watch-statement-seconds'] as $option) {
            $this->assertStringContainsString($option, $out);
        }
    }

    public function test_the_watch_refuses_a_wrong_usage_with_2(): void
    {
        foreach ([[], ['--db-name=mysql', '--out-dir=/tmp', '--done-file=/tmp/x'], ['--db-name=peak_sim_measure_a_1', '--out-dir=/no/such', '--done-file=/tmp/x'], ['--bogus']] as $args) {
            [$code, , $err] = self::invoke('variance-watch', $args);

            $this->assertSame(2, $code, $err);
        }
    }

    public function test_the_app_key_is_passed_by_name_and_never_printed(): void
    {
        $script = (string) file_get_contents(self::script('variance-run'));

        $this->assertMatchesRegularExpression('/exec_env=\(-e PEAK_MEASURE_APP_KEY\s/', $script, 'the key must be passed to compose exec by name');
        $this->assertSame(0, preg_match('/-e "?PEAK_MEASURE_APP_KEY=/', $script), 'the key must not be on a command line');
        $this->assertSame(0, preg_match('/(echo|printf|cat)\b[^\n]*\$\{?(app_key|PEAK_MEASURE_APP_KEY)/', $script), 'the key must not be printed');
    }

    public function test_the_scripts_have_no_kill_in_them(): void
    {
        foreach (['variance-run', 'variance-watch'] as $name) {
            $code = array_filter(explode("\n", (string) file_get_contents(self::script($name))), static fn (string $line): bool => !str_starts_with(ltrim($line), '#'));
            $this->assertSame(0, preg_match('/(^|[\s;&|(`])(kill|killall|pkill|timeout)(\s|$)/m', implode("\n", $code)), $name . ' must not stop anything');
        }
    }
}
