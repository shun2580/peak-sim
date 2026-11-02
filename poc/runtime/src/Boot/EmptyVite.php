<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Boot;

/**
 * The asset helper of an app whose front end is not built: every call answers with nothing, so a page that asks for its
 * script and style tags is rendered without them. The tags carry no data and no query. It is a plain class, not a
 * subclass of the framework's helper: the helper's methods differ from one Laravel version to the next, and the
 * container takes any object for it.
 */
final class EmptyVite
{
    /** @param mixed ...$arguments */
    public function __invoke(mixed ...$arguments): object
    {
        return new class() implements \Stringable {
            public function __toString(): string
            {
                return '';
            }

            public function toHtml(): string
            {
                return '';
            }
        };
    }

    public function __toString(): string
    {
        return '';
    }

    /**
     * Setters (`use…`, `with…`) answer with the helper itself, a list of assets with an empty list, anything else
     * (`asset`, `content`, `manifest`, …) with an empty string.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (str_starts_with($method, 'use') || str_starts_with($method, 'with')) {
            return $this;
        }
        if ($method === 'preloadedAssets') {
            return [];
        }

        return '';
    }
}
