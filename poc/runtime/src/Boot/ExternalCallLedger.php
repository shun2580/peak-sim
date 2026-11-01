<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Boot;

/**
 * Hosts the app tried to reach over HTTP. Only the host is kept: never the path, query or credentials of the URL.
 */
final class ExternalCallLedger
{
    /** @var list<string> */
    private array $hosts = [];

    public function add(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : '(unknown host)';
        if (!in_array($host, $this->hosts, true)) {
            $this->hosts[] = $host;
        }
    }

    /** @return list<string> in the order of first sight */
    public function hosts(): array
    {
        return $this->hosts;
    }

    public function clear(): void
    {
        $this->hosts = [];
    }
}
