<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

/** One request to send. Built by the caller (route catalog, body builder); the Requester does not guess. */
final class RequestSpec
{
    /**
     * @param array<string, string>      $headers
     * @param array<string, mixed>|null  $body    sent as JSON when $json, else as a form
     * @param array<string, string>      $query   the query string of a GET (credentials an app reads from the request's input); empty by default
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $headers = [],
        public readonly ?array $body = null,
        public readonly bool $json = true,
        public readonly bool $csrf = false,
        public readonly array $query = [],
    ) {
    }
}
