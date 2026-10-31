<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Type\Type;

/** Variables of the body being walked. Types are immutable, so a fork only copies two small arrays. */
final class Scope
{
    /** @var array<string, Type> */
    public array $vars = [];

    /** @var array<string, int> number of loop frames around the place a variable was first defined */
    public array $declaredAt = [];

    public function __construct(
        /** App-relative file of the code being walked. */
        public readonly string $file,
        /** The class self:: and static:: mean. */
        public readonly ?string $class,
        /** The class parent:: is taken from. */
        public readonly ?string $declClass,
        public readonly ?Type $selfType,
        public readonly ReturnBag $returns = new ReturnBag(),
    ) {
    }

    public function fork(): self
    {
        return clone $this;
    }

    public function get(string $name): ?Type
    {
        return $this->vars[$name] ?? null;
    }

    public function set(string $name, Type $type, int $frameCount): void
    {
        if (!isset($this->vars[$name])) {
            $this->declaredAt[$name] = $frameCount;
        }
        $this->vars[$name] = $type;
    }
}
