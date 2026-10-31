<?php

declare(strict_types=1);

namespace PeakSim\Tests\Support;

/**
 * One directory of tests/Fixtures/Apps. Adding a case means adding a directory:
 *
 *   <case>/expect.php   what the tool must say (see Expectation)
 *   <case>/export.php   the artisan export (see MiniAppExport)
 *   <case>/SPEC.md      what the app must contain (for whoever writes it)
 *   <case>/app, bootstrap, routes, resources, composer.json   the app itself
 *
 * Directories whose name starts with "_" or "." are not cases (the shared notes live in _kit).
 */
final class MiniAppCase
{
    private function __construct(
        public readonly string $name,
        public readonly string $dir,
    ) {
    }

    /** @return list<self> sorted by name */
    public static function discover(string $appsDir): array
    {
        $cases = [];
        foreach (scandir($appsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '_' || $entry[0] === '.' || !is_dir($appsDir . '/' . $entry)) {
                continue;
            }
            $cases[] = new self($entry, $appsDir . '/' . $entry);
        }
        usort($cases, static fn (self $a, self $b): int => strcmp($a->name, $b->name));

        return $cases;
    }

    /** @return array<string, mixed> */
    public function expect(): array
    {
        $value = self::load($this->dir . '/expect.php');
        if (!is_array($value)) {
            throw new \UnexpectedValueException("{$this->name}/expect.php must return an array");
        }

        return $value;
    }

    /** @return array{route_list: list<array<string, mixed>>, model_show: array<string, array<string, mixed>>} */
    public function export(): array
    {
        $value = self::load($this->dir . '/export.php');
        if (!is_array($value) || !isset($value['route_list'], $value['model_show'])) {
            throw new \UnexpectedValueException("{$this->name}/export.php must return MiniAppExport::build(...)");
        }

        return $value;
    }

    /** Missing pieces of the case itself (not of the app). @return list<string> */
    public function problems(): array
    {
        $out = [];
        foreach (['expect.php', 'export.php', 'SPEC.md'] as $file) {
            if (!is_file($this->dir . '/' . $file)) {
                $out[] = "{$this->name}: {$file} is missing";
            }
        }
        if ($out === []) {
            $expect = $this->expect();
            if (!is_string($expect['description'] ?? null) || $expect['description'] === '') {
                $out[] = "{$this->name}: expect.php needs a 'description'";
            }
            $uris = array_map(static fn (array $r): string => '/' . ltrim($r['uri'], '/'), $this->export()['route_list']);
            foreach (array_keys($expect['routes'] ?? []) as $uri) {
                if (!in_array($uri, $uris, true)) {
                    $out[] = "{$this->name}: expect.php names {$uri}, which export.php does not route";
                }
            }
        }

        return $out;
    }

    private static function load(string $file): mixed
    {
        return (static fn (string $f): mixed => require $f)($file);
    }
}
