<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/** The options of the in-container driver (`bin/inner/measure.php`); the host script `bin/measure` passes them on. */
final class Options
{
    public const DEFAULT_BASE = GeneratorPlanner::BASE;

    /**
     * @param list<string> $only
     * @param list<string> $nTables the tables that grow together for every route (generator only); empty = each route's own
     */
    private function __construct(
        public readonly string $data,
        public readonly array $only,
        public readonly ?string $expected,
        public readonly int $base,
        public readonly ?string $profile = null,
        public readonly bool $gateBypass = true,
        public readonly bool $warm = true,
        public readonly ?string $tapDir = null,
        public readonly array $nTables = [],
        public readonly bool $analyzeAfterBuild = false,
        /** Drop each database once it is measured. Default off: a measured database is kept (see `bin/measure --purge`). */
        public readonly bool $dropDb = false,
    ) {
    }

    /**
     * @param list<string> $argv
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(array $argv): self
    {
        $values = [];
        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
                throw new \InvalidArgumentException('unexpected argument: ' . $arg);
            }
            [$name, $value] = explode('=', substr($arg, 2), 2);
            if (!in_array($name, ['data', 'only', 'expected', 'base', 'profile', 'gate-bypass', 'warm', 'tap-dir', 'n-tables', 'analyze-after-build', 'drop-db'], true)) {
                throw new \InvalidArgumentException('unknown option: --' . $name);
            }
            $values[$name] = $value;
        }

        $data = $values['data'] ?? 'generator';
        if (!in_array($data, ['seeder', 'generator'], true)) {
            throw new \InvalidArgumentException('--data takes seeder or generator');
        }

        $only = [];
        if (isset($values['only'])) {
            $only = array_values(array_filter(explode(',', $values['only']), static fn (string $s): bool => $s !== ''));
            if ($only === []) {
                throw new \InvalidArgumentException('--only needs at least one route');
            }
        }

        $expected = $values['expected'] ?? null;
        if ($expected !== null && (!str_starts_with($expected, '/') || str_contains($expected, '..') || str_contains($expected, "\0"))) {
            throw new \InvalidArgumentException('--expected must be an absolute path without ".."');
        }

        $base = self::DEFAULT_BASE;
        if (isset($values['base'])) {
            if (preg_match('/^[1-9][0-9]{0,3}$/D', $values['base']) !== 1) {
                throw new \InvalidArgumentException('--base must be a positive integer');
            }
            $base = (int) $values['base'];
        }

        $profile = $values['profile'] ?? null;
        if ($profile !== null && preg_match(Profile::NAME_PATTERN, $profile) !== 1) {
            throw new \InvalidArgumentException('--profile takes the name of a file in profiles/ (lowercase letters, digits, - and _)');
        }

        $gate = $values['gate-bypass'] ?? 'on';
        if (!in_array($gate, ['on', 'off'], true)) {
            throw new \InvalidArgumentException('--gate-bypass takes on or off');
        }

        $warm = $values['warm'] ?? 'on';
        if (!in_array($warm, ['on', 'off'], true)) {
            throw new \InvalidArgumentException('--warm takes on or off');
        }

        // Set only by `bin/capture`: the directory (inside the container) where each cold request of the N axis leaves its
        // per-statement record. `bin/measure` never passes it, and without it nothing is tapped.
        $tapDir = $values['tap-dir'] ?? null;
        if ($tapDir !== null && (!str_starts_with($tapDir, '/') || str_contains($tapDir, '..') || str_contains($tapDir, "\0"))) {
            throw new \InvalidArgumentException('--tap-dir must be an absolute path without ".."');
        }

        // The tables that all grow together for every route (the lab database's way). Plain names, as the generator takes them.
        $nTables = [];
        if (isset($values['n-tables'])) {
            if (preg_match('/^[a-z0-9_]+(,[a-z0-9_]+)*$/D', $values['n-tables']) !== 1) {
                throw new \InvalidArgumentException('--n-tables takes table names (lowercase letters, digits and _) separated by commas');
            }
            if ($data !== 'generator') {
                throw new \InvalidArgumentException('--n-tables is for the generator; the seeder has its own shape');
            }
            $nTables = explode(',', $values['n-tables']);
        }

        // Set only by `bin/capture --analyze-after-build=on`: right after each database is built, ANALYZE TABLE on every table and
        // STATS_AUTO_RECALC=0 (the statistics state the EXPLAIN step puts its databases in). Off, nothing is run and the run is as it was.
        $analyze = $values['analyze-after-build'] ?? 'off';
        if (!in_array($analyze, ['on', 'off'], true)) {
            throw new \InvalidArgumentException('--analyze-after-build takes on or off');
        }

        // Set only by `bin/measure --purge` and `bin/capture --purge`. Off (the default), a database the run has measured is kept,
        // and nothing but the driver's own rebuild of a database a request changed (and the inspection database) is dropped.
        $dropDb = $values['drop-db'] ?? 'off';
        if (!in_array($dropDb, ['on', 'off'], true)) {
            throw new \InvalidArgumentException('--drop-db takes on or off');
        }

        return new self($data, $only, $expected, $base, $profile, $gate === 'on', $warm === 'on', $tapDir, $nTables, $analyze === 'on', $dropDb === 'on');
    }

    /**
     * What the driver hands to the inspection child (`bin/inner/inspect.php`): the scale, and the options that child
     * needs to prepare the same routes the run measures, with the same app knowledge.
     *
     * @return list<string>
     */
    public function inspectionArguments(int $scale): array
    {
        return [
            '--scale=' . $scale,
            ...($this->only === [] ? [] : ['--only=' . implode(',', $this->only)]),
            ...($this->profile === null ? [] : ['--profile=' . $this->profile]),
        ];
    }

    public function wants(string $route, ?string $method = null): bool
    {
        if ($this->only === []) {
            return true;
        }
        foreach ($this->only as $needle) {
            // A needle written "METHOD uri" (an upper-case method, a space, the whole uri) names one route: both must be equal.
            if ($method !== null && preg_match('/^([A-Z]+) (\/.*)$/', $needle, $m) === 1) {
                if ($m[1] === $method && $m[2] === $route) {
                    return true;
                }

                continue;
            }
            // A needle that starts with "=" must equal the whole uri; any other needle is a substring match.
            if (str_starts_with($needle, '=') ? $route === substr($needle, 1) : str_contains($route, $needle)) {
                return true;
            }
        }

        return false;
    }
}
