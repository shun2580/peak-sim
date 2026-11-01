<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Route\RouteSpec;

/**
 * One route, ready to be measured: the filled URI, who calls it, what body it gets. When something stopped the
 * preparation, `failClass` says what (from the fixed vocabulary) and the route is recorded without being run.
 */
final class RouteTask
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     * @param list<string>              $guessed arguments whose value was made up, not read from a row (see ArgumentResolver)
     */
    public function __construct(
        public readonly RouteSpec $spec,
        public readonly ?string $uri,
        public readonly ?string $failClass = null,
        public readonly ?string $failDetail = null,
        public readonly ?string $actAs = null,
        public readonly ?array $body = null,
        public readonly bool $form = false,
        public readonly bool $csrf = false,
        public readonly array $headers = [],
        public readonly array $guessed = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $s = $this->spec;

        return [
            'spec' => [
                'uri' => $s->uri, 'method' => $s->method, 'methods' => $s->methods, 'middleware' => $s->middleware,
                'parameters' => $s->parameters, 'action' => $s->action, 'static' => $s->static, 'in_runtime' => $s->inRuntime,
            ],
            'uri' => $this->uri,
            'fail_class' => $this->failClass,
            'fail_detail' => $this->failDetail,
            'act_as' => $this->actAs,
            'body' => $this->body,
            'form' => $this->form,
            'csrf' => $this->csrf,
            'headers' => $this->headers,
            'guessed' => $this->guessed,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $s = $data['spec'];

        return new self(
            new RouteSpec((string) $s['uri'], (string) $s['method'], array_values($s['methods']), array_values($s['middleware']), $s['parameters'], $s['action'], $s['static'], (bool) $s['in_runtime']),
            $data['uri'],
            $data['fail_class'],
            $data['fail_detail'],
            $data['act_as'],
            $data['body'],
            (bool) $data['form'],
            (bool) $data['csrf'],
            $data['headers'],
            array_values($data['guessed'] ?? []),
        );
    }
}
