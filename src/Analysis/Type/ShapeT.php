<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

/** An array literal: key to type. Used to find models inside a returned array. */
final class ShapeT extends Type
{
    /** @param array<string, Type> $entries */
    public function __construct(
        public readonly array $entries,
    ) {
    }

    public function with(string $key, Type $type): self
    {
        return new self([...$this->entries, $key => $type]);
    }

    public function fingerprint(): string
    {
        $parts = [];
        foreach ($this->entries as $key => $type) {
            $parts[] = $key . '=' . $type->fingerprint();
        }

        return 'shape{' . implode(',', $parts) . '}';
    }
}
