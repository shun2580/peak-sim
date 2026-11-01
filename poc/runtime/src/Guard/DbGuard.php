<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Guard;

use PeakSim\Runtime\Config\MeasureRefused;

/**
 * The second gate for safety condition 2 (the first gate is the privileges of the `measure` MySQL user).
 * Pure functions; no I/O except the injected resolver.
 */
final class DbGuard
{
    public const NAME_PREFIX = 'peak_sim_measure_';

    /** MySQL allows 64 characters in a database name: 17 (prefix) + at most 47. */
    public const NAME_PATTERN = '/^peak_sim_measure_[a-z0-9_]{8,47}$/D';

    /** The only host a measurement may connect to: the DB service of the measurement compose. */
    public const ALLOWED_HOSTS = ['measure-db'];

    /** The only MySQL user the PHP container is given. */
    public const ALLOWED_USER = 'measure';

    public static function isMeasureDbName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /** @throws MeasureRefused */
    public static function assertDbName(string $name): void
    {
        if (!self::isMeasureDbName($name)) {
            throw new MeasureRefused(sprintf(
                'refused: database name %s is not a measurement database (must match %s)',
                self::printable($name),
                self::NAME_PATTERN,
            ));
        }
    }

    /** @throws MeasureRefused */
    public static function assertHost(string $host): void
    {
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new MeasureRefused(sprintf(
                'refused: database host %s is not the measurement DB service (allowed: %s)',
                self::printable($host),
                implode(', ', self::ALLOWED_HOSTS),
            ));
        }
    }

    /** @throws MeasureRefused */
    public static function assertUser(string $user): void
    {
        if ($user !== self::ALLOWED_USER) {
            throw new MeasureRefused(sprintf(
                'refused: database user %s is not the measurement user (%s)',
                self::printable($user),
                self::ALLOWED_USER,
            ));
        }
    }

    /**
     * The host name must resolve to a private address (10/8, 172.16/12, 192.168/16). $resolver maps a host name to
     * an IPv4 string, or returns the name unchanged when it cannot resolve (like gethostbyname).
     *
     * @param callable(string): string $resolver
     *
     * @throws MeasureRefused
     */
    public static function assertResolvesPrivate(string $host, callable $resolver): void
    {
        $ip = $resolver($host);
        if (!self::isPrivateIpv4($ip)) {
            throw new MeasureRefused(sprintf(
                'refused: database host %s resolves to %s, which is not a private address',
                self::printable($host),
                self::printable($ip),
            ));
        }
    }

    public static function isPrivateIpv4(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $parts = array_map('intval', explode('.', $ip));

        return $parts[0] === 10
            || ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31)
            || ($parts[0] === 192 && $parts[1] === 168);
    }

    /** Keeps refusal messages on one short, safe line (the value is untrusted). */
    private static function printable(string $value): string
    {
        $cut = substr($value, 0, 80);

        return json_encode($cut, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '""';
    }
}
