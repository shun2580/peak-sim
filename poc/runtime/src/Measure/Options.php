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
            if (!in_array($name, ['data', 'only', 'expected', 'base'], true)) {
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

        return new self($data, $only, $expected, $base);
    }

    public function wants(string $route): bool
    {
        if ($this->only === []) {
            return true;
        }
        foreach ($this->only as $needle) {
            if (str_contains($route, $needle)) {
                return true;
            }
        }

        return false;
    }
}
