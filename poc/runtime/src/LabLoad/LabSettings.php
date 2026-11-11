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
     * @return array{php_max_execution_time: string, php_fpm_request_terminate_timeout: string, nginx_fastcgi_read_timeout: string, k6_request_timeout: string|null, k6_request_timeout_note: string}
     *
     * @throws \InvalidArgumentException a figure is missing or not unique
     */
    public static function fromReadings(string $nginxConfig, string $fpmConfig, string $iniProbe, ?string $k6Timeout): array
    {
        return [
            'php_max_execution_time' => self::iniMaxExecutionTime($iniProbe),
            'php_fpm_request_terminate_timeout' => self::fpmTerminateTimeout($fpmConfig),
            'nginx_fastcgi_read_timeout' => self::nginxReadTimeout($nginxConfig),
            'k6_request_timeout' => $k6Timeout,
            'k6_request_timeout_note' => $k6Timeout === null
                ? 'not given: the load tool used its own default (60 s for k6 2.3.0)'
                : 'given to every request of the step (REQUEST_TIMEOUT)',
        ];
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
