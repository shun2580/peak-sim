<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Config;

use PeakSim\Runtime\Guard\DbGuard;

/**
 * Input of a measurement: environment variables only. The target app's `.env` is never read.
 *
 *   PEAK_MEASURE_DB_HOST       must be the compose DB service (DbGuard::ALLOWED_HOSTS)
 *   PEAK_MEASURE_DB_PORT       optional, default 3306
 *   PEAK_MEASURE_DB_USER       must be `measure`
 *   PEAK_MEASURE_DB_PASSWORD   required
 *   PEAK_MEASURE_RUN_ID        6-24 chars of [a-z0-9]; see generateRunId()
 *   PEAK_MEASURE_APP_ROOT      absolute path of the target app (mounted read-only)
 *   PEAK_MEASURE_STATIC_JSON   optional absolute path of the static analysis output
 *   PEAK_MEASURE_SCALES        optional, default "100,1000,10000"; one or more ascending points
 *   PEAK_MEASURE_OUT_DIR       optional, default /out
 *   PEAK_MEASURE_DB_TAG        optional, 1-8 chars [a-z][a-z0-9]*; names the data plan a database belongs to
 *
 * There is no way to pass a database name in: the tool builds `peak_sim_measure_<run id>[_<tag>]_<scale>` itself.
 * Without a tag only the configured scales are valid (the first form). With a tag, any scale from 0 to MAX_SCALE is
 * valid: the driver measures an empty system (scale 0) and a second sweep (the children per parent) in databases of
 * their own, and the tag keeps those names apart from the main sweep's.
 */
final class MeasureConfig
{
    public const DEFAULT_SCALES = [100, 1000, 10000];

    private const MAX_SCALE = 9_999_999;

    /**
     * @param list<int> $scales
     */
    private function __construct(
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $runId,
        public readonly string $appRoot,
        public readonly ?string $staticJson,
        public readonly array $scales,
        public readonly string $outDir,
        public readonly string $dbTag = '',
    ) {
    }

    /**
     * @param array<string, mixed> $env
     *
     * @throws MeasureRefused
     */
    public static function fromEnv(array $env): self
    {
        $host = self::required($env, 'PEAK_MEASURE_DB_HOST');
        DbGuard::assertHost($host);

        $user = self::required($env, 'PEAK_MEASURE_DB_USER');
        DbGuard::assertUser($user);

        $password = self::required($env, 'PEAK_MEASURE_DB_PASSWORD');

        $portText = self::optional($env, 'PEAK_MEASURE_DB_PORT') ?? '3306';
        if (preg_match('/^[0-9]{1,5}$/D', $portText) !== 1 || (int) $portText < 1 || (int) $portText > 65535) {
            throw new MeasureRefused('refused: PEAK_MEASURE_DB_PORT is not a port number');
        }

        $runId = self::required($env, 'PEAK_MEASURE_RUN_ID');
        if (preg_match('/^[a-z0-9]{6,24}$/D', $runId) !== 1) {
            throw new MeasureRefused('refused: PEAK_MEASURE_RUN_ID must be 6-24 characters of [a-z0-9]');
        }

        $appRoot = self::absolutePath(self::required($env, 'PEAK_MEASURE_APP_ROOT'), 'PEAK_MEASURE_APP_ROOT');

        $staticJson = self::optional($env, 'PEAK_MEASURE_STATIC_JSON');
        if ($staticJson !== null) {
            $staticJson = self::absolutePath($staticJson, 'PEAK_MEASURE_STATIC_JSON');
        }

        $scales = self::scales(self::optional($env, 'PEAK_MEASURE_SCALES'));
        $outDir = self::absolutePath(self::optional($env, 'PEAK_MEASURE_OUT_DIR') ?? '/out', 'PEAK_MEASURE_OUT_DIR');

        $tag = self::optional($env, 'PEAK_MEASURE_DB_TAG') ?? '';
        if ($tag !== '' && preg_match('/^[a-z][a-z0-9]{0,7}$/D', $tag) !== 1) {
            throw new MeasureRefused('refused: PEAK_MEASURE_DB_TAG must be 1-8 characters of [a-z0-9], starting with a letter');
        }

        return new self($host, (int) $portText, $user, $password, $runId, $appRoot, $staticJson, $scales, $outDir, $tag);
    }

    /** A fresh run id for the parent process; children receive it through PEAK_MEASURE_RUN_ID. */
    public static function generateRunId(): string
    {
        return bin2hex(random_bytes(6));
    }

    /**
     * The measurement database for one scale. The only place a name is built.
     *
     * @throws MeasureRefused
     */
    public function dbNameForScale(int $scale): string
    {
        if ($this->dbTag === '') {
            if (!in_array($scale, $this->scales, true)) {
                throw new MeasureRefused(sprintf('refused: scale %d is not one of the configured scales', $scale));
            }
            $name = DbGuard::NAME_PREFIX . $this->runId . '_' . $scale;
        } else {
            if ($scale < 0 || $scale > self::MAX_SCALE) {
                throw new MeasureRefused(sprintf('refused: scale %d is outside 0..%d', $scale, self::MAX_SCALE));
            }
            $name = DbGuard::NAME_PREFIX . $this->runId . '_' . $this->dbTag . '_' . $scale;
        }
        DbGuard::assertDbName($name);

        return $name;
    }

    /** @param array<string, mixed> $env */
    private static function required(array $env, string $key): string
    {
        $value = self::optional($env, $key);
        if ($value === null) {
            throw new MeasureRefused(sprintf('refused: %s is not set (the measurement takes its input from the environment only)', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $env */
    private static function optional(array $env, string $key): ?string
    {
        if (!array_key_exists($key, $env)) {
            return null;
        }
        $value = $env[$key];
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private static function absolutePath(string $path, string $key): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '..')) {
            throw new MeasureRefused(sprintf('refused: %s must be an absolute path without ".."', $key));
        }

        return $path;
    }

    /** @return list<int> */
    private static function scales(?string $text): array
    {
        if ($text === null) {
            return self::DEFAULT_SCALES;
        }
        $scales = [];
        foreach (explode(',', $text) as $part) {
            if (preg_match('/^[0-9]{1,7}$/D', $part) !== 1 || (int) $part < 1 || (int) $part > self::MAX_SCALE) {
                throw new MeasureRefused('refused: PEAK_MEASURE_SCALES must be a comma-separated list of positive integers');
            }
            $scales[] = (int) $part;
        }
        $sorted = $scales;
        sort($sorted);
        if ($sorted !== $scales || count(array_unique($scales)) !== count($scales)) {
            throw new MeasureRefused('refused: PEAK_MEASURE_SCALES must be strictly ascending');
        }
        if ($scales === []) {
            throw new MeasureRefused('refused: PEAK_MEASURE_SCALES needs at least 1 point');
        }

        return $scales;
    }
}
