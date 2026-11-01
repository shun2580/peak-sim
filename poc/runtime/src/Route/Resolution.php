<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/** The outcome of filling a route's parameters: the values, and for every one that could not be filled, why. */
final class Resolution
{
    /**
     * @param array<string, string|int> $values
     * @param list<array{param: string, reason: string, class?: string}> $unfilled `class` is set when an app profile explains why
     * @param list<string> $omitted optional parameters left out
     */
    public function __construct(
        public readonly array $values,
        public readonly array $unfilled,
        public readonly array $omitted = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->unfilled === [];
    }

    /** The URI with the values put in, or null when some parameter is unfilled. */
    public function fill(string $uri): ?string
    {
        if (! $this->ok()) {
            return null;
        }
        $filled = $uri;
        foreach ($this->omitted as $name) {
            $filled = (string) preg_replace('#/?\{' . preg_quote($name, '#') . '(?::\w+)?\?\}#', '', $filled);
        }
        foreach ($this->values as $name => $value) {
            $filled = (string) preg_replace('#\{' . preg_quote((string) $name, '#') . '(?::\w+)?\??\}#', strtr(rawurlencode((string) $value), ['\\' => '\\\\', '$' => '\\$']), $filled);
        }

        return $filled === '' ? '/' : $filled;
    }
}
