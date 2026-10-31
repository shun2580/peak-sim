<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Type;

use PeakSim\Model\Cardinality;
use PeakSim\Model\Delivery;

/**
 * An array or a Collection: the element type and where its size comes from.
 * $window is true for the argument of a chunk callback (the window of rows, not the whole set).
 */
final class ListT extends Type
{
    public function __construct(
        public readonly Type $elem,
        public readonly Cardinality $card,
        public readonly Delivery $delivery = Delivery::Materialized,
        public readonly bool $window = false,
    ) {
    }

    public function withElem(Type $elem): self
    {
        return new self($elem, $this->card, $this->delivery, $this->window);
    }

    public function withCard(Cardinality $card): self
    {
        return new self($this->elem, $card, $this->delivery, $this->window);
    }

    public function withDelivery(Delivery $delivery): self
    {
        return new self($this->elem, $this->card, $delivery, $this->window);
    }

    public function withWindow(bool $window = true): self
    {
        return new self($this->elem, $this->card, $this->delivery, $window);
    }

    public function fingerprint(): string
    {
        return 'list<' . $this->elem->fingerprint() . '>:' . $this->card->fingerprint() . ':' . $this->delivery->value
            . ($this->window ? ':window' : '');
    }
}
