<?php

declare(strict_types=1);

/*
 * 合成アプリ生成(解析時間の計測用)。
 *
 *   php scripts/synthetic-app/generate.php <出力ディレクトリ> [ルート数=300]
 *
 * fixtures/laravel-app の各コントローラーを、ルート数分だけ複製する。
 * 1ルートごとに、コントローラーとその依存(app/ 内の参照先・プロバイダ・モデル)を
 * 名前空間 App\Synthetic\R<3桁連番> の下へ写す。クラス名は元のまま、連番は名前空間に載せる
 * (FQCN が一意になり、PSR-4 の対応もそのまま保たれる)。
 *
 * 決定的: 同じ入力に同じ出力。時刻・絶対パスは出さない。
 * 出力先は空(存在しないか空のディレクトリ)でなければ拒否する。既存の内容は消さない。
 * 生成物は tests/ や fixtures/ には置かない。解析の入力としてだけ使う。
 */

exit(synthMain($argv));

function synthMain(array $argv): int
{
    if (count($argv) < 2 || count($argv) > 3) {
        fwrite(STDERR, "usage: php generate.php <output-dir> [routes=300]\n");
        return 2;
    }

    $out = $argv[1];
    $routesArg = $argv[2] ?? '300';
    if (!ctype_digit($routesArg) || (int) $routesArg < 1) {
        fwrite(STDERR, "routes must be a positive integer\n");
        return 2;
    }
    $routes = (int) $routesArg;

    if (file_exists($out)) {
        if (!is_dir($out) || count(array_diff(scandir($out) ?: [], ['.', '..'])) > 0) {
            fwrite(STDERR, "output directory must be empty or absent; refusing to overwrite\n");
            return 1;
        }
    } elseif (!mkdir($out, 0777, true) && !is_dir($out)) {
        fwrite(STDERR, "cannot create output directory\n");
        return 1;
    }

    $root = dirname(__DIR__, 2);
    $source = $root . '/fixtures/laravel-app';
    $app = $source . '/app';
    $exportDir = $root . '/fixtures/artisan-export';

    $baseRoutes = array_values(array_filter(
        json_decode((string) file_get_contents($exportDir . '/route-list.json'), true, 512, JSON_THROW_ON_ERROR),
        static fn (array $r): bool => str_starts_with((string) $r['action'], 'App\\Http\\Controllers\\')
    ));
    if ($baseRoutes === []) {
        fwrite(STDERR, "no controller routes in the export\n");
        return 3;
    }

    $baseModels = modelExports($exportDir);
    $providerFqns = providerClasses($source . '/bootstrap/providers.php');
    $modelRels = array_map(
        static fn (string $p): string => 'Models/' . basename($p),
        glob($app . '/Models/*.php') ?: []
    );
    sort($modelRels);

    // ルート1本ごとの依存の閉包は同じ基底コントローラーなら同じなので、基底ごとに1度だけ求める。
    $closureByBase = [];
    $providerRels = array_map(static fn (string $fqn): string => fqnToRel($fqn), $providerFqns);

    $routeList = [];
    $providerLines = [];
    for ($i = 1; $i <= $routes; $i++) {
        $prefix = sprintf('App\\Synthetic\\R%03d', $i);
        $base = $baseRoutes[($i - 1) % count($baseRoutes)];
        $baseIdx = ($i - 1) % count($baseRoutes);

        if (!isset($closureByBase[$baseIdx])) {
            $controllerRel = fqnToRel(explode('@', (string) $base['action'])[0]);
            $closureByBase[$baseIdx] = closureOf($app, array_merge([$controllerRel], $providerRels, $modelRels));
        }

        foreach ($closureByBase[$baseIdx] as $rel) {
            $text = rewriteNs((string) file_get_contents($app . '/' . $rel), $prefix);
            writeFile($out . '/app/Synthetic/' . sprintf('R%03d', $i) . '/' . $rel, $text);
        }

        foreach ($modelRels as $rel) {
            $cls = 'App\\Models\\' . basename($rel, '.php');
            if (!isset($baseModels[$cls])) {
                continue;
            }
            $data = $baseModels[$cls];
            array_walk_recursive($data, static function (&$v) use ($prefix): void {
                if (is_string($v)) {
                    $v = rewriteNs($v, $prefix);
                }
            });
            writeFile(
                $out . '/model-show/' . sprintf('R%03d', $i) . '_' . basename($rel, '.php') . '.json',
                jsonText($data)
            );
        }

        $entry = $base;
        $entry['uri'] = sprintf('syn/%03d/%s', $i, $base['uri']);
        $entry['action'] = rewriteNs((string) $base['action'], $prefix);
        $routeList[] = $entry;

        foreach ($providerFqns as $fqn) {
            $providerLines[] = '    \\' . rewriteNs('App\\' . substr($fqn, strlen('App\\')), $prefix) . '::class,';
        }
    }

    writeFile($out . '/route-list.json', jsonText($routeList));
    writeFile($out . '/composer.json', jsonText([
        'name' => 'peak-sim/synthetic-app',
        'description' => 'Generated for analysis timing. Do not edit.',
        'type' => 'project',
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));
    writeFile($out . '/bootstrap/providers.php', "<?php\n\nreturn [\n" . implode("\n", $providerLines) . "\n];\n");

    copyViews($source . '/resources/views', $out . '/resources/views');

    return 0;
}

/** 'App\Http\Controllers\Ng\X' → 'Http/Controllers/Ng/X.php'(app/ からの相対) */
function fqnToRel(string $fqn): string
{
    return str_replace('\\', '/', substr($fqn, strlen('App\\'))) . '.php';
}

/**
 * 与えた起点から、参照をたどって必要な app/ 内のファイルを集める。
 * 参照は (1) App\… の完全修飾名 (2) 同じディレクトリの兄弟ファイルのうち、名前が本文に現れるもの。
 *
 * @param list<string> $seeds app/ からの相対パス
 * @return list<string> 昇順
 */
function closureOf(string $app, array $seeds): array
{
    $result = [];
    $queue = $seeds;
    while ($queue !== []) {
        $rel = array_shift($queue);
        if (isset($result[$rel]) || !is_file($app . '/' . $rel)) {
            continue;
        }
        $result[$rel] = true;
        $text = (string) file_get_contents($app . '/' . $rel);

        preg_match_all('/\bApp\\\\[A-Za-z0-9_\\\\]+/', $text, $m);
        foreach ($m[0] as $fqn) {
            $queue[] = fqnToRel(rtrim($fqn, '\\'));
        }

        $dir = dirname($rel);
        $dirAbs = $app . ($dir === '.' ? '' : '/' . $dir);
        $self = basename($rel, '.php');
        foreach (glob($dirAbs . '/*.php') ?: [] as $sibling) {
            $name = basename($sibling, '.php');
            if ($name === $self) {
                continue;
            }
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $text) === 1) {
                $queue[] = ($dir === '.' ? '' : $dir . '/') . $name . '.php';
            }
        }
    }
    $rels = array_keys($result);
    sort($rels);

    return $rels;
}

/** App\ で始まる参照を $prefix(App\Synthetic\R###)へ写す。 */
function rewriteNs(string $text, string $prefix): string
{
    return (string) preg_replace_callback(
        '/\bApp\\\\(?=[A-Za-z])/',
        static fn (): string => $prefix . '\\',
        $text
    );
}

/** @return list<string> bootstrap/providers.php が use しているクラス(App\… の完全修飾名) */
function providerClasses(string $providersPhp): array
{
    preg_match_all('/^use (App\\\\[A-Za-z0-9_\\\\]+);/m', (string) file_get_contents($providersPhp), $m);

    return $m[1];
}

/** @return array<string,array<string,mixed>> FQCN => model-show の中身 */
function modelExports(string $exportDir): array
{
    $models = [];
    foreach (glob($exportDir . '/model-show/*.json') ?: [] as $path) {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $models[ltrim((string) $data['class'], '\\')] = $data;
    }

    return $models;
}

function jsonText(mixed $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

function writeFile(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, $content);
}

function copyViews(string $from, string $to): void
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $files[] = substr($file->getPathname(), strlen($from) + 1);
        }
    }
    sort($files);
    foreach ($files as $rel) {
        writeFile($to . '/' . $rel, (string) file_get_contents($from . '/' . $rel));
    }
}
