<?php

declare(strict_types=1);

namespace PeakSim\Benchmarks\RealApps;

/**
 * The fixed answers (truth.json): loading, checking the shape, and the one place where a growth word becomes a degree.
 */
final class Truth
{
    public const SCHEMA = 'peak-sim-truth/1';

    /** @var array<string, int> */
    private const DEGREES = ['O(1)' => 0, 'O(N)' => 1, 'O(N*M)' => 2];

    /**
     * @return array<string, mixed>
     */
    public static function load(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException("cannot read {$path}");
        }

        return self::parse($text);
    }

    /**
     * @return array<string, mixed>
     */
    public static function parse(string $json): array
    {
        try {
            $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('truth is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($doc) || ($doc['schema'] ?? null) !== self::SCHEMA || !is_array($doc['routes'] ?? null) || !is_array($doc['apps'] ?? null)) {
            throw new \RuntimeException('truth has the wrong shape (expected schema ' . self::SCHEMA . ', apps and routes)');
        }

        $seen = [];
        foreach ($doc['routes'] as $i => $route) {
            foreach (['id', 'app', 'method', 'uri', 'axes'] as $key) {
                if (!is_array($route) || !array_key_exists($key, $route)) {
                    throw new \RuntimeException("truth route #{$i} lacks '{$key}'");
                }
            }
            if (isset($seen[$route['id']])) {
                throw new \RuntimeException("truth has the id {$route['id']} twice");
            }
            $seen[$route['id']] = true;
            if (!isset($doc['apps'][$route['app']])) {
                throw new \RuntimeException("truth route {$route['id']} names an app that is not listed");
            }
            foreach ($route['axes'] as $axisName => $axis) {
                if (!in_array($axisName, ['query', 'memory'], true)) {
                    throw new \RuntimeException("truth route {$route['id']} has the unknown axis {$axisName}");
                }
                $growth = $axis['growth'] ?? null;
                if ($growth !== null && $growth !== 'conditional' && self::degree($growth) === null) {
                    throw new \RuntimeException("truth route {$route['id']}: unknown growth '{$growth}'");
                }
                if ($growth === 'conditional' && !is_int($axis['growth_if_condition_degree'] ?? null)) {
                    throw new \RuntimeException("truth route {$route['id']}: a conditional answer needs growth_if_condition_degree");
                }
            }
        }

        return $doc;
    }

    /** O(1) is 0, O(N) is 1, O(N*M) is 2; anything else has no degree. */
    public static function degree(?string $growth): ?int
    {
        return $growth === null ? null : (self::DEGREES[$growth] ?? null);
    }
}
