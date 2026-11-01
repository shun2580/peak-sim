<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Guard;

/**
 * Safety condition 1, the preflight: from inside the PHP container, nothing outside may be reachable and the DB
 * service must be. Negative controls must all fail; the positive control must succeed. A single reachable outside
 * address means the container can leave, and the measurement must not start (exit code 11).
 *
 * The probes are injected so the verdict logic is tested without a network.
 */
final class EgressProbe
{
    public const EXIT_CODE = 11;

    /** Public addresses that must not be reachable. */
    public const OUTSIDE_TCP = [['1.1.1.1', 443], ['8.8.8.8', 53]];

    public const OUTSIDE_NAME = 'example.com';

    /**
     * @param callable(string): string                  $resolve    host name to IPv4 (the name itself when unresolved)
     * @param callable(string, int): bool               $connect    true when a TCP connection could be made
     * @param callable(): ?string                       $gateway    the default gateway, or null when there is no default route
     * @param callable(string): bool                    $httpFetch  true when an HTTP GET to the URL returned a response
     */
    public function __construct(
        private $resolve,
        private $connect,
        private $gateway,
        private $httpFetch,
    ) {
    }

    /** The real probes for use inside the container. */
    public static function native(): self
    {
        return new self(
            static fn (string $host): string => gethostbyname($host),
            static function (string $host, int $port): bool {
                $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $error, 3);
                if ($socket === false) {
                    return false;
                }
                fclose($socket);

                return true;
            },
            static function (): ?string {
                $route = @file('/proc/net/route', FILE_IGNORE_NEW_LINES) ?: [];
                foreach (array_slice($route, 1) as $line) {
                    $cols = preg_split('/\s+/', trim($line)) ?: [];
                    if (($cols[1] ?? '') === '00000000' && ($cols[2] ?? '') !== '00000000') {
                        $hex = str_split($cols[2], 2);

                        return implode('.', array_reverse(array_map('hexdec', $hex)));
                    }
                }

                return null;
            },
            static function (string $url): bool {
                $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);

                return @file_get_contents($url, false, $context) !== false;
            },
        );
    }

    /**
     * @return list<array{name: string, kind: string, ok: bool, detail: string}>
     */
    public function run(string $dbHost, int $dbPort): array
    {
        $results = [];

        $ip = ($this->resolve)(self::OUTSIDE_NAME);
        $resolved = $ip !== self::OUTSIDE_NAME;
        $results[] = self::negative('dns:' . self::OUTSIDE_NAME, $resolved, $resolved ? 'resolved to ' . $ip : 'did not resolve');

        foreach (self::OUTSIDE_TCP as [$host, $port]) {
            $reachable = ($this->connect)($host, $port);
            $results[] = self::negative(sprintf('tcp:%s:%d', $host, $port), $reachable, $reachable ? 'connected' : 'could not connect');
        }

        $reachable = ($this->httpFetch)('http://' . self::OUTSIDE_NAME . '/');
        $results[] = self::negative('http:' . self::OUTSIDE_NAME, $reachable, $reachable ? 'got a response' : 'no response');

        $gateway = ($this->gateway)();
        $results[] = self::negative('default-route', $gateway !== null, $gateway !== null ? 'default gateway ' . $gateway : 'no default route');

        $dbIp = ($this->resolve)($dbHost);
        $dbConnect = ($this->connect)($dbHost, $dbPort);
        $results[] = [
            'name' => sprintf('tcp:%s:%d', $dbHost, $dbPort),
            'kind' => 'positive',
            'ok' => $dbConnect,
            'detail' => $dbConnect ? 'connected' : 'could not connect to the DB service',
        ];
        $results[] = [
            'name' => 'dns:' . $dbHost,
            'kind' => 'positive',
            'ok' => DbGuard::isPrivateIpv4($dbIp),
            'detail' => $dbIp === $dbHost ? 'did not resolve' : 'resolved to ' . $dbIp,
        ];

        return $results;
    }

    /**
     * @param list<array{name: string, kind: string, ok: bool, detail: string}> $results
     */
    public static function allOk(array $results): bool
    {
        if ($results === []) {
            return false;
        }
        foreach ($results as $result) {
            if (!$result['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * A negative control passes when the outside was NOT reachable.
     *
     * @return array{name: string, kind: string, ok: bool, detail: string}
     */
    private static function negative(string $name, bool $reachable, string $detail): array
    {
        return ['name' => $name, 'kind' => 'negative', 'ok' => !$reachable, 'detail' => $detail];
    }
}
