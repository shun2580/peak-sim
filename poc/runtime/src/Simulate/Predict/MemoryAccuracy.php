<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/**
 * Which routes have a peak-memory formula that was checked and held (simulator-spec.md section 6.2): the routes whose measured peak
 * memory was within the minimum tolerance (+-30%) of the formula in every extrapolation test that measured them.
 *
 * It is read from a file made from the records of those tests (`memory-accuracy.json`):
 *   { "schema_version": "1", "source": "...", "tolerance": 0.3, "within": [route, ...], "outside": [route, ...] }
 * A route in neither list was not checked, and is not shown either.
 */
final class MemoryAccuracy
{
    public const FILE = 'memory-accuracy.json';

    public const SCHEMA_VERSION = '1';

    public const WITHIN = 'within';

    public const OUTSIDE = 'outside';

    public const NOT_CHECKED = 'not_checked';

    /**
     * @param array<string, true> $within
     * @param array<string, true> $outside
     */
    private function __construct(private readonly array $within, private readonly array $outside, public readonly string $source)
    {
    }

    /** @param array<string, mixed> $data the decoded file */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('memory accuracy file: schema_version must be "' . self::SCHEMA_VERSION . '"');
        }
        $within = array_fill_keys(array_map('strval', (array) ($data['within'] ?? [])), true);
        $outside = array_fill_keys(array_map('strval', (array) ($data['outside'] ?? [])), true);
        $both = array_intersect_key($within, $outside);
        if ($both !== []) {
            throw new \InvalidArgumentException('memory accuracy file: a route is in both lists: ' . implode(', ', array_keys($both)));
        }

        return new self($within, $outside, (string) ($data['source'] ?? ''));
    }

    /** No file: nothing was checked, so no route is shown. */
    public static function none(): self
    {
        return new self([], [], 'none');
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \InvalidArgumentException('memory accuracy file is not readable: ' . $path);
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('memory accuracy file is not a JSON object');
        }

        return self::fromArray($data);
    }

    /** @return 'within'|'outside'|'not_checked' */
    public function stateOf(string $route): string
    {
        return isset($this->within[$route]) ? self::WITHIN : (isset($this->outside[$route]) ? self::OUTSIDE : self::NOT_CHECKED);
    }
}
