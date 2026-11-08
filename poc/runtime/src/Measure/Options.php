<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

/** The options of the in-container driver (`bin/inner/measure.php`); the host script `bin/measure` passes them on. */
final class Options
{
    public const DEFAULT_BASE = GeneratorPlanner::BASE;

    /** @param list<string> $only */
    private function __construct(
        public readonly string $data,
        public readonly array $only,
        public readonly ?string $expected,
        public readonly int $base,
        public readonly ?string $profile = null,
        public readonly bool $gateBypass = true,
        public readonly bool $warm = true,
        public readonly ?string $tapDir = null,
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
            if (!in_array($name, ['data', 'only', 'expected', 'base', 'profile', 'gate-bypass', 'warm', 'tap-dir'], true)) {
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

        return new self($data, $only, $expected, $base, $profile, $gate === 'on', $warm === 'on', $tapDir);
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

    public function wants(string $route): bool
    {
        if ($this->only === []) {
            return true;
        }
        foreach ($this->only as $needle) {
            // A needle that starts with "=" must equal the whole uri; any other needle is a substring match.
            if (str_starts_with($needle, '=') ? $route === substr($needle, 1) : str_contains($route, $needle)) {
                return true;
            }
        }

        return false;
    }
}
