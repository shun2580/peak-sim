<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit;

use PeakSim\Cli\Application;
use PHPUnit\Framework\TestCase;

/**
 * The runtime proof of concept (poc/) must never become part of the official tool: not in the phar, not in the
 * autoloader, not reachable from src/, and not visible in the official CLI. This guard lives in the ordinary suite
 * and needs nothing from the PoC (no Docker, no skip).
 */
final class PocIsolationTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<mixed> */
    private static function json(string $file): array
    {
        $data = json_decode((string) file_get_contents(self::root() . '/' . $file), true);
        self::assertIsArray($data, $file . ' must be valid JSON');

        return $data;
    }

    public function testPharBoxConfigDoesNotIncludePoc(): void
    {
        $box = self::json('box.json.dist');

        $this->assertNotEmpty($box['directories'] ?? [], 'box.json.dist must list the directories it packs');
        $this->assertSame(['src'], array_values($box['directories']), 'the phar packs src/ only');
        $this->assertStringNotContainsString('poc', (string) json_encode($box['finder'] ?? []));
        $this->assertArrayNotHasKey('files', $box);
    }

    public function testComposerAutoloadDoesNotIncludePoc(): void
    {
        $composer = self::json('composer.json');
        $paths = [];
        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $kind) {
                $entries = $composer[$section][$kind] ?? [];
                array_walk_recursive($entries, static function (mixed $value) use (&$paths): void {
                    $paths[] = (string) $value;
                });
            }
        }

        $this->assertNotEmpty($paths);
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('poc', $path, 'composer.json autoload must not reach poc/: ' . $path);
        }
    }

    public function testSourceNeverReachesIntoPoc(): void
    {
        $found = [];
        $scanned = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $scanned++;
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                if (str_contains($token[1], 'PeakSim\\Runtime') || preg_match('~(^|[\'"/])poc/~', $token[1]) === 1) {
                    $found[] = substr($file->getPathname(), strlen(self::root()) + 1) . ' mentions ' . $token[1];
                }
            }
        }

        $this->assertGreaterThan(0, $scanned, 'the scan must see files');
        $this->assertSame([], $found);
    }

    public function testOfficialHelpDoesNotMentionTheRuntimeMode(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);

        $exit = (new Application())->run(['peak-sim', '--help'], $stdout, $stderr);
        rewind($stdout);
        $help = (string) stream_get_contents($stdout);

        $this->assertSame(0, $exit);
        $this->assertNotSame('', $help);
        // "docker" is not listed: the help legitimately shows `docker compose exec` as an --artisan-command example.
        foreach (['runtime', 'measure', 'poc'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $help, 'the official help must not mention "' . $word . '"');
        }
    }
}
