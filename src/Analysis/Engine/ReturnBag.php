<?php

declare(strict_types=1);

namespace PeakSim\Analysis\Engine;

use PeakSim\Analysis\Type\Type;

/** The values a method body returns. Branch scopes share one bag with the scope they were forked from. */
final class ReturnBag
{
    /** @var list<Type> */
    public array $types = [];
}
