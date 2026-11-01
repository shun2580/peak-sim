<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/** A request body built from validation rules, with what could not be satisfied. */
final class BodyBuild
{
    /**
     * @param array<string, mixed> $fields field => value (a FileField for an upload)
     * @param list<array{field: string, reason: string}> $unfilled fields left out because nothing could fill them
     * @param list<string> $unsupported rules the builder cannot satisfy (the field still gets a plain string)
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $unfilled,
        public readonly array $unsupported,
    ) {
    }

    /** @return list<string> the top-level fields that must be sent as files */
    public function files(): array
    {
        $names = [];
        foreach ($this->fields as $name => $value) {
            if ($value instanceof FileField) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
