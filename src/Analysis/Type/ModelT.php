<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

use PeakSim\Model\Origin;

/** One row of a model. $eager holds the relations already loaded (dotted names are nested loads). */
final class ModelT extends Type
{
    /** @var list<string> */
    public readonly array $eager;

    /** @param list<string> $eager */
    public function __construct(
        public readonly string $class,
        array $eager = [],
        /** Which row set this row came from. */
        public readonly ?Origin $origin = null,
    ) {
        $this->eager = self::normalizeNames($eager);
    }

    /** @param list<string> $names */
    public function withEager(array $names): self
    {
        return new self($this->class, [...$this->eager, ...$names], $this->origin);
    }

    public function withOrigin(?Origin $origin): self
    {
        return new self($this->class, $this->eager, $origin);
    }

    public function fingerprint(): string
    {
        return 'model:' . $this->class . '[' . implode(',', $this->eager) . ']' . ($this->origin === null ? '' : '@' . $this->origin->fingerprint());
    }
}
