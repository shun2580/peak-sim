<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Boot;

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureRefused;
use PHPUnit\Framework\TestCase;

/**
 * One APP_KEY per run. The app encrypts some columns with it (Koel's Subsonic API key), so the process that builds the
 * database and the processes that measure must share it. The key is made up for the run and sealed in the container;
 * it is still never written to a report, a summary or a log.
 */
final class AppBooterAppKeyTest extends TestCase
{
    private const KEY = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    public function test_without_the_variable_a_fresh_key_is_made_as_before(): void
    {
        $first = AppBooter::appKeyFrom([]);
        $second = AppBooter::appKeyFrom([]);

        self::assertMatchesRegularExpression('#^base64:[A-Za-z0-9+/]{43}=$#', $first);
        self::assertNotSame($first, $second);
    }

    public function test_an_empty_variable_counts_as_not_set(): void
    {
        self::assertNotSame('', AppBooter::appKeyFrom([AppBooter::APP_KEY_ENV => '']));
        self::assertNotSame(AppBooter::appKeyFrom([AppBooter::APP_KEY_ENV => '']), AppBooter::appKeyFrom([AppBooter::APP_KEY_ENV => '']));
    }

    public function test_the_variable_wins_and_is_returned_as_given(): void
    {
        self::assertSame(self::KEY, AppBooter::appKeyFrom([AppBooter::APP_KEY_ENV => self::KEY]));
    }

    /** @return iterable<string, array{string}> */
    public static function badKeys(): iterable
    {
        yield 'no prefix' => ['MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='];
        yield 'too short' => ['base64:c2hvcnQ='];
        yield 'sixteen bytes' => ['base64:' . 'MDEyMzQ1Njc4OWFiY2RlZg=='];
        yield 'not base64' => ['base64:' . str_repeat('!', 44)];
        yield 'thirty-three bytes' => ['base64:' . 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWZH'];
        yield 'trailing newline' => [self::KEY . "\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
    public function test_a_key_that_cannot_work_is_refused_without_quoting_it(string $bad): void
    {
        try {
            AppBooter::appKeyFrom([AppBooter::APP_KEY_ENV => $bad]);
            self::fail('a malformed key should be refused');
        } catch (MeasureRefused $e) {
            self::assertStringContainsString(AppBooter::APP_KEY_ENV, $e->getMessage());
            self::assertStringNotContainsString($bad, $e->getMessage());
            self::assertStringNotContainsString(trim(substr($bad, 7)), $e->getMessage());
        }
    }

    /** Two real processes with the same variable: what one encrypts the other can decrypt. */
    public function test_two_processes_of_one_run_can_read_each_others_encrypted_values(): void
    {
        $encrypted = self::child('encrypt', ['PEAK_MEASURE_APP_KEY' => self::KEY]);
        $decrypted = self::child('decrypt', ['PEAK_MEASURE_APP_KEY' => self::KEY], $encrypted);

        self::assertSame('subsonic-api-key-0123', $decrypted);
    }

    /**
     * Without the variable every process has its own key (the old behaviour): the value cannot be read back.
     *
     * With the wrong key CBC decryption usually fails, but about once in 256 it passes the padding check by chance and
     * returns garbage instead of false. So the test asks for what matters, that the original never comes back, and not
     * for the failure itself (which made this test fail now and then).
     */
    public function test_without_the_variable_two_processes_cannot_read_each_others_values(): void
    {
        $encrypted = self::child('encrypt', []);
        $decrypted = self::child('decrypt', [], $encrypted);

        self::assertNotSame('subsonic-api-key-0123', $decrypted);
    }

    public function test_a_child_never_prints_the_key(): void
    {
        $out = self::child('encrypt', ['PEAK_MEASURE_APP_KEY' => self::KEY]);

        self::assertStringNotContainsString(substr(self::KEY, 7), $out);
        self::assertStringNotContainsString(self::KEY, $out);
    }

    /** @param array<string, string> $env */
    private static function child(string $mode, array $env, string $input = ''): string
    {
        $code = <<<'PHP'
            require getenv('PEAK_BOOTSTRAP');
            $key = base64_decode(substr(PeakSim\Runtime\Boot\AppBooter::appKeyFrom(getenv()), 7), true);
            $mode = getenv('PEAK_CHILD_MODE');
            $cipher = 'aes-256-cbc';
            if ($mode === 'encrypt') {
                $iv = random_bytes(16);
                echo base64_encode($iv . openssl_encrypt('subsonic-api-key-0123', $cipher, $key, OPENSSL_RAW_DATA, $iv));
            } else {
                $raw = base64_decode((string) getenv('PEAK_CHILD_INPUT'), true);
                $plain = openssl_decrypt(substr($raw, 16), $cipher, $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
                echo $plain === false ? 'undecryptable' : $plain;
            }
            PHP;
        $env += ['PEAK_BOOTSTRAP' => dirname(__DIR__, 3) . '/bootstrap.php', 'PEAK_CHILD_MODE' => $mode, 'PEAK_CHILD_INPUT' => $input, 'PATH' => '/usr/local/bin:/usr/bin:/bin'];
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, $err);

        return $out;
    }
}
