<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * The three limits that can end a request in the lab, as they were when a step ran (docs/simulator-spec.md section 7.4.1): the
 * PHP execution-time limit as php-fpm's own requests see it, the time the load tool waits for one response, and the time the web
 * server waits for php-fpm. The record of a step keeps them so that a step is never read under the wrong settings.
 *
 * This class only reads text that was already collected from the containers (it touches none); a figure it cannot find stops the
 * step instead of being guessed, in the same way an unreadable sample does.
 */
final class LabSettings
{
    /**
     * @param string      $nginxConfig the output of `nginx -T` in the web container
     * @param string      $fpmConfig   the output of `php-fpm -tt` in the app container
     * @param string      $iniProbe    the body that `poc/lab/diagnosis/ini-probe.php` gave through php-fpm (a JSON object)
     * @param string|null $k6Timeout   the value given to the load tool as REQUEST_TIMEOUT, or null when none was given
     *
     * @return array{php_max_execution_time: string, php_memory_limit: string|null, php_fpm_request_terminate_timeout: string, nginx_fastcgi_read_timeout: string, k6_request_timeout: string|null, k6_request_timeout_note: string}
     *
     * @throws \InvalidArgumentException a figure is missing or not unique
     */
    public static function fromReadings(string $nginxConfig, string $fpmConfig, string $iniProbe, ?string $k6Timeout): array
    {
        return [
            'php_max_execution_time' => self::iniMaxExecutionTime($iniProbe),
            // read when the probe gives it (it always has); a probe without it is kept as null and refused by `violations` for any requirement
            'php_memory_limit' => self::iniMemoryLimit($iniProbe),
            'php_fpm_request_terminate_timeout' => self::fpmTerminateTimeout($fpmConfig),
            'nginx_fastcgi_read_timeout' => self::nginxReadTimeout($nginxConfig),
            'k6_request_timeout' => $k6Timeout,
            'k6_request_timeout_note' => $k6Timeout === null
                ? 'not given: the load tool used its own default (60 s for k6 2.3.0)'
                : 'given to every request of the step (REQUEST_TIMEOUT)',
        ];
    }

    /** What the measurement of the ladder (the re-check, B) needs: no PHP limit, no pool limit, a web server that waits as long as k6. */
    public const UNLIMITED = 'unlimited';

    /** What the mark's one-request-at-a-time check needs: the PHP limit the application really has. */
    public const LIMITED = 'limited';

    /** The PHP limit of the mark, in seconds (the lab's default, left as it is for the mark; docs/simulator-spec.md section 6.4). */
    public const LIMITED_PHP_SECONDS = 30;

    /** The PHP memory limit B is measured with (2026-11-16 maintainer's ruling, spec 7.4.2, D206/D208): 4G, so that a heavy route is not cut at 512M. */
    public const UNLIMITED_MEMORY_BYTES = 4 * 1024 * 1024 * 1024;

    /** The PHP memory limit of the lab by default, left as it is for the mark (spec 7.4.2): 512M. */
    public const LIMITED_MEMORY_BYTES = 512 * 1024 * 1024;

    /**
     * Whether the limits read from the lab are the ones a measurement needs. Fails closed: a value that is missing or that cannot be
     * read as a duration is a violation, never a pass.
     *
     * - `unlimited` (B and the calibration that is measured with the limit off): `max_execution_time` is 0, `memory_limit` is 4G,
     *   php-fpm's `request_terminate_timeout` is 0, and the web server's `fastcgi_read_timeout` is at least the timeout given to the load tool
     *   (which must be given, or there is nothing to compare with);
     * - `limited` (the mark, measured with the limit on): `max_execution_time` is 30 and `memory_limit` is 512M.
     *
     * @param array<string, mixed> $settings what `fromReadings` returned
     *
     * @return list<string> what does not match, one sentence each; empty when the lab is as the measurement needs
     *
     * @throws \InvalidArgumentException the requirement is not one of the two
     */
    public static function violations(array $settings, string $require, ?string $k6Timeout): array
    {
        if ($require === self::LIMITED) {
            $found = [];
            $php = self::seconds($settings['php_max_execution_time'] ?? null);
            if ($php === null || $php !== (float) self::LIMITED_PHP_SECONDS) {
                $found[] = sprintf('max_execution_time is %s, the mark is measured with %d', self::show($settings['php_max_execution_time'] ?? null), self::LIMITED_PHP_SECONDS);
            }
            if (self::bytes($settings['php_memory_limit'] ?? null) !== self::LIMITED_MEMORY_BYTES) {
                $found[] = sprintf('memory_limit is %s, the mark is measured with 512M', self::show($settings['php_memory_limit'] ?? null));
            }

            return $found;
        }
        if ($require !== self::UNLIMITED) {
            throw new \InvalidArgumentException("the requirement must be '" . self::UNLIMITED . "' or '" . self::LIMITED . "' (got '$require')");
        }
        $found = [];
        $php = self::seconds($settings['php_max_execution_time'] ?? null);
        if ($php !== 0.0) {
            $found[] = sprintf('max_execution_time is %s, this measurement needs 0 (no limit)', self::show($settings['php_max_execution_time'] ?? null));
        }
        if (self::bytes($settings['php_memory_limit'] ?? null) !== self::UNLIMITED_MEMORY_BYTES) {
            $found[] = sprintf('memory_limit is %s, this measurement needs 4G', self::show($settings['php_memory_limit'] ?? null));
        }
        $pool = self::seconds($settings['php_fpm_request_terminate_timeout'] ?? null);
        if ($pool !== 0.0) {
            $found[] = sprintf('request_terminate_timeout is %s, this measurement needs 0', self::show($settings['php_fpm_request_terminate_timeout'] ?? null));
        }
        $k6 = $k6Timeout === null ? null : self::seconds($k6Timeout);
        $nginx = self::seconds($settings['nginx_fastcgi_read_timeout'] ?? null);
        if ($k6 === null) {
            $found[] = 'the timeout given to the load tool is missing or not a duration, so the web server\'s limit cannot be compared with it';
        } elseif ($nginx === null || $nginx < $k6) {
            $found[] = sprintf('fastcgi_read_timeout is %s, shorter than the load tool\'s %s: nginx would cut a slow response first', self::show($settings['nginx_fastcgi_read_timeout'] ?? null), $k6Timeout);
        }

        return $found;
    }

    /** @return float|null seconds of a plain number or a number with ms, s, m, h or d; null when it is anything else */
    private static function seconds(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        if (preg_match('/^(\d+(?:\.\d+)?)(ms|s|m|h|d)?$/', trim((string) $value), $m) !== 1) {
            return null;
        }
        $unit = ['ms' => 0.001, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2] ?? 's'];

        return (float) $m[1] * $unit;
    }

    /** @return int|null bytes of a PHP shorthand (a plain number, or a number with K, M or G); null for anything else, -1 (no limit) included */
    private static function bytes(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        if (preg_match('/^(\d+)([KMG])?$/i', trim((string) $value), $m) !== 1) {
            return null;
        }
        $unit = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][strtoupper($m[2] ?? '')];

        return (int) $m[1] * $unit;
    }

    private static function show(mixed $value): string
    {
        return is_scalar($value) ? "'" . $value . "'" : 'missing';
    }

    /** The value a request through php-fpm sees ("0" is no limit). */
    private static function iniMaxExecutionTime(string $body): string
    {
        $data = json_decode(trim($body), true);
        if (!is_array($data)) {
            // the FastCGI client may print a header line before the body: take the first line that is a JSON object
            foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
                $data = json_decode(trim($line), true);
                if (is_array($data)) {
                    break;
                }
            }
        }
        if (!is_array($data) || !isset($data['max_execution_time']) || !is_scalar($data['max_execution_time'])) {
            throw new \InvalidArgumentException('the php-fpm reading of max_execution_time is missing (the probe gave no JSON with it)');
        }
        if (($data['sapi'] ?? null) !== 'fpm-fcgi') {
            throw new \InvalidArgumentException('the php-fpm reading did not come through php-fpm (sapi is not fpm-fcgi)');
        }

        return (string) $data['max_execution_time'];
    }

    /** The memory_limit a request through php-fpm sees, as the probe printed it ("4G", "512M"); null when the probe does not give it. */
    private static function iniMemoryLimit(string $body): ?string
    {
        $data = json_decode(trim($body), true);
        if (!is_array($data)) {
            foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
                $data = json_decode(trim($line), true);
                if (is_array($data)) {
                    break;
                }
            }
        }

        return is_array($data) && isset($data['memory_limit']) && is_scalar($data['memory_limit']) ? (string) $data['memory_limit'] : null;
    }

    /** `request_terminate_timeout = 0s` in the pool as php-fpm parsed it. */
    private static function fpmTerminateTimeout(string $text): string
    {
        // the name followed by `=`: `request_terminate_timeout_track_finished` is another setting and does not match
        if (preg_match_all('/request_terminate_timeout\s*=\s*(\S+)/', $text, $m) < 1) {
            throw new \InvalidArgumentException('request_terminate_timeout is not in the output of php-fpm -tt');
        }
        $values = array_values(array_unique($m[1]));
        if (count($values) !== 1) {
            throw new \InvalidArgumentException('request_terminate_timeout has more than one value in php-fpm -tt: ' . implode(', ', $values));
        }

        return $values[0];
    }

    /** `fastcgi_read_timeout <v>;` in the loaded nginx configuration (comments are not counted). */
    private static function nginxReadTimeout(string $text): string
    {
        $values = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^fastcgi_read_timeout\s+(\S+?)\s*;/', $line, $m) === 1) {
                $values[] = $m[1];
            }
        }
        $values = array_values(array_unique($values));
        if ($values === []) {
            throw new \InvalidArgumentException('fastcgi_read_timeout is not in the loaded nginx configuration');
        }
        if (count($values) !== 1) {
            throw new \InvalidArgumentException('fastcgi_read_timeout has more than one value in the nginx configuration: ' . implode(', ', $values));
        }

        return $values[0];
    }
}
