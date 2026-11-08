<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\EstimateCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `bin/estimate` answers a wrong usage with exit 2 and the usage text, and prints the estimate as JSON otherwise. */
final class EstimateCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-estimate-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/capture.json', json_encode(['representative_scale' => 10000, 'routes' => []]));
        file_put_contents($this->dir . '/explain.json', json_encode(['scale' => 100000, 'routes' => []]));
        file_put_contents($this->dir . '/bad.json', '{not json');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function invoke(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new EstimateCommand($out, $err))->run($args);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function wrongUsages(): iterable
    {
        yield 'no arguments' => [[], '--capture is required'];
        yield 'unknown candidate' => [['--capture=a', '--explain=b', '--candidate=H4'], '--candidate must be one of'];
        yield 'unknown argument' => [['--bogus'], 'unknown argument'];
    }

    /** @param list<string> $args */
    #[DataProvider('wrongUsages')]
    public function test_wrong_usage_is_exit_two_with_the_usage_text(array $args, string $message): void
    {
        [$code, $out, $err] = self::invoke($args);
        $this->assertSame(2, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString($message, $err);
        $this->assertStringContainsString('usage: bin/estimate', $err);
    }

    public function test_an_unreadable_or_bad_input_is_exit_two(): void
    {
        $this->assertSame(2, self::invoke(['--capture=' . $this->dir . '/nope.json', '--explain=' . $this->dir . '/explain.json', '--candidate=H1'])[0]);
        [$code, , $err] = self::invoke(['--capture=' . $this->dir . '/bad.json', '--explain=' . $this->dir . '/explain.json', '--candidate=H1']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('is not JSON', $err);
        $this->assertSame(2, self::invoke(['--capture=' . $this->dir . '/capture.json', '--explain=' . $this->dir . '/explain.json', '--candidate=H1', '--at=0'])[0]);
    }

    public function test_it_prints_the_estimate_with_the_size_of_the_explain_file_by_default(): void
    {
        [$code, $out] = self::invoke(['--capture=' . $this->dir . '/capture.json', '--explain=' . $this->dir . '/explain.json', '--candidate=H2']);
        $this->assertSame(0, $code);
        $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('H2', $decoded['candidate']);
        $this->assertSame(100000, $decoded['at']);
        $this->assertSame(10000, $decoded['small_scale']);
    }
}
