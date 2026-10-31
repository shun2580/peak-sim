<?php

declare(strict_types=1);

namespace PeakSim\Tests\Unit\SyntheticApp;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * scripts/synthetic-app/generate.php の自己試験。
 * 小さい数(3ルート)で生成し、構造・構文・決定性を確かめる。
 */
final class GenerateTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/synthetic-app/generate.php';
    private const ROUTES = 3;

    /** @var list<string> 後片付けする一時ディレクトリ */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->removeTree($dir);
        }
        $this->dirs = [];
    }

    public function testGeneratesRouteListWithOneEntryPerRouteAndUniqueUris(): void
    {
        $out = $this->generate(self::ROUTES);

        $routes = json_decode((string) file_get_contents($out . '/route-list.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(self::ROUTES, $routes);

        $uris = array_column($routes, 'uri');
        self::assertSame($uris, array_values(array_unique($uris)));
        self::assertSame(['domain', 'method', 'uri', 'name', 'action', 'middleware', 'path'], array_keys($routes[0]));
    }

    public function testEachRouteActionPointsToItsOwnNamespaceCopy(): void
    {
        $out = $this->generate(self::ROUTES);

        $routes = json_decode((string) file_get_contents($out . '/route-list.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($routes as $i => $route) {
            $ns = sprintf('App\\Synthetic\\R%03d\\', $i + 1);
            self::assertStringStartsWith($ns, $route['action']);
            $class = substr(explode('@', $route['action'])[0], strlen($ns));
            self::assertFileExists($out . '/app/Synthetic/' . sprintf('R%03d', $i + 1) . '/' . str_replace('\\', '/', $class) . '.php');
        }
    }

    public function testEveryGeneratedPhpFileParsesAsPhp82Syntax(): void
    {
        $out = $this->generate(self::ROUTES);

        $files = $this->phpFiles($out);
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            // TOKEN_PARSE は構文エラーで ParseError を投げる(php -l 相当。ホストに依存しない)。
            token_get_all((string) file_get_contents($file), TOKEN_PARSE);
        }
    }

    public function testNamespaceDeclarationMatchesDirectoryForPsr4(): void
    {
        $out = $this->generate(self::ROUTES);

        foreach ($this->phpFiles($out . '/app') as $file) {
            $rel = substr($file, strlen($out . '/app/'));
            $expected = 'App\\' . str_replace('/', '\\', dirname($rel));
            $body = (string) file_get_contents($file);
            self::assertMatchesRegularExpression(
                '/^namespace ' . preg_quote($expected, '/') . ';/m',
                $body,
                "namespace mismatch in {$rel}"
            );
        }
    }

    public function testEveryCopiedAppReferenceResolvesToAFileOrDirectory(): void
    {
        $out = $this->generate(self::ROUTES);

        foreach ($this->phpFiles($out . '/app') as $file) {
            $body = (string) file_get_contents($file);
            preg_match_all('/\bApp\\\\Synthetic\\\\R\d{3}(?:\\\\[A-Za-z0-9_]+)+/', $body, $m);
            foreach (array_unique($m[0]) as $fqn) {
                $rel = substr(str_replace('\\', '/', $fqn), strlen('App/'));
                $path = $out . '/app/' . $rel;
                self::assertTrue(
                    is_file($path . '.php') || is_dir($path),
                    'unresolved ' . $fqn . ' in ' . substr($file, strlen($out) + 1)
                );
            }
        }
    }

    public function testProvidersFileListsOneSetPerRoute(): void
    {
        $out = $this->generate(self::ROUTES);

        $body = (string) file_get_contents($out . '/bootstrap/providers.php');
        for ($i = 1; $i <= self::ROUTES; $i++) {
            self::assertStringContainsString(sprintf('App\\Synthetic\\R%03d\\Providers\\AppServiceProvider::class', $i), $body);
        }
    }

    public function testModelShowFilesMatchCopiedModelClasses(): void
    {
        $out = $this->generate(self::ROUTES);

        $classes = [];
        foreach (glob($out . '/model-show/*.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $classes[] = $data['class'];
        }
        self::assertCount(5 * self::ROUTES, $classes);
        self::assertSame(count($classes), count(array_unique($classes)));
        foreach ($classes as $class) {
            self::assertMatchesRegularExpression('/^App\\\\Synthetic\\\\R\d{3}\\\\Models\\\\[A-Za-z]+$/', $class);
        }
    }

    public function testTwoRunsProduceByteIdenticalTrees(): void
    {
        $a = $this->generate(self::ROUTES);
        $b = $this->generate(self::ROUTES);

        self::assertSame($this->treeDigest($a), $this->treeDigest($b));
    }

    public function testRefusesToWriteIntoNonEmptyDirectoryAndLeavesItAlone(): void
    {
        $out = $this->newDir();
        file_put_contents($out . '/keep.txt', 'keep');

        [$code] = $this->runScript([$out, '1']);

        self::assertSame(1, $code);
        self::assertSame(['keep.txt'], array_values(array_diff(scandir($out) ?: [], ['.', '..'])));
    }

    public function testRejectsRouteCountBelowOne(): void
    {
        $out = $this->newDir() . '/x';

        [$code] = $this->runScript([$out, '0']);

        self::assertSame(2, $code);
    }

    private function generate(int $routes): string
    {
        $out = $this->newDir() . '/app-' . $routes;
        [$code, $stderr] = $this->runScript([$out, (string) $routes]);
        self::assertSame(0, $code, $stderr);

        return $out;
    }

    /** @return array{0:int,1:string} 終了コードと標準エラー */
    private function runScript(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, self::SCRIPT], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $errFile = tempnam(sys_get_temp_dir(), 'synth-err-');
        exec($escaped . ' 2>' . escapeshellarg((string) $errFile) . ' >/dev/null', $lines, $code);
        $stderr = (string) file_get_contents((string) $errFile);
        unlink((string) $errFile);

        return [$code, $stderr];
    }

    private function newDir(): string
    {
        $dir = sys_get_temp_dir() . '/peak-synth-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return array<string,string> 相対パス => sha1 */
    private function treeDigest(string $root): array
    {
        $digest = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $digest[substr($file->getPathname(), strlen($root) + 1)] = sha1_file($file->getPathname());
            }
        }
        ksort($digest);

        return $digest;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
