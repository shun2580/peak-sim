<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Run\Attempt;

/** What one `bin/measure-one` process is asked: one route on one database. */
final class MeasureRequest
{
    private const PHASE_NAMES = [Attempt::WARMUP => 'warmup', Attempt::COLD => 'cold', Attempt::WARM => 'warm'];

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     * @param list<int>                 $phases  Attempt::WARMUP, COLD, WARM
     * @param array<string, string>     $query   the query string of a GET; a secret is only a token here (RequestCredentials)
     */
    public function __construct(
        public readonly string $tag,
        public readonly int $scale,
        public readonly string $axis,
        public readonly string $route,
        public readonly string $uri,
        public readonly string $method,
        public readonly ?string $actAs,
        public readonly ?array $body,
        public readonly bool $form,
        public readonly bool $csrf,
        public readonly array $headers,
        public readonly bool $gateBypass,
        public readonly array $phases,
        public readonly array $query = [],
    ) {
    }

    public function withGateBypass(): self
    {
        return new self($this->tag, $this->scale, $this->axis, $this->route, $this->uri, $this->method, $this->actAs, $this->body, $this->form, $this->csrf, $this->headers, true, $this->phases, $this->query);
    }

    /** @return list<string> the arguments of `bin/measure-one` */
    public function toArguments(): array
    {
        $args = ['--scale=' . $this->scale, '--uri=' . $this->uri, '--method=' . $this->method, '--route=' . $this->route, '--axis=' . $this->axis];
        foreach ($this->headers as $name => $value) {
            $args[] = '--header=' . $name . ':' . $value;
        }
        foreach ($this->query as $name => $value) {
            $args[] = '--query=' . $name . '=' . $value;
        }
        if ($this->body !== null) {
            $args[] = '--body-json=' . ($this->body === [] ? '{}' : json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        if ($this->form) {
            $args[] = '--form';
        }
        if ($this->csrf) {
            $args[] = '--csrf';
        }
        if ($this->actAs !== null) {
            $args[] = '--act-as=' . $this->actAs;
        }
        if ($this->gateBypass) {
            $args[] = '--gate-bypass';
        }
        $args[] = '--phases=' . implode(',', array_map(static fn (int $p): string => self::PHASE_NAMES[$p], $this->phases));

        return $args;
    }
}
